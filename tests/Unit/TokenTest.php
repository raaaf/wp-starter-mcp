<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\Config;
use WpStarterMcp\Intent;
use WpStarterMcp\OAuth\Authorize;
use WpStarterMcp\OAuth\Token;
use WpStarterMcp\RateLimiter;

final class TokenTest extends WpTestCase
{
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private string $log = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'wpsmcp-log');
        ini_set('error_log', $this->log);
        $this->allowUser(3);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
    }

    private function token(): Token
    {
        return new Token($this->store(), new RateLimiter(fn (): int => $this->now));
    }

    /** Runs the consent step and returns the issued code. */
    private function code(): string
    {
        (new Intent(fn (): int => $this->now))->create(3);
        $params = $this->authParams($this->challenge(self::VERIFIER));
        $request = Authorize::validate($params);
        $this->assertIsArray($request);
        $action = 'wpsmcp_consent_' . hash('sha256', (string) json_encode($request));
        $result = (new Authorize($this->store(), new Intent(fn (): int => $this->now)))
            ->process('POST', $params + ['decision' => 'allow', '_wpnonce' => 'nonce-' . $action], 3, true);
        parse_str((string) parse_url((string) $result['url'], PHP_URL_QUERY), $query);

        return (string) $query['code'];
    }

    /** @return array<string, string> */
    private function codeGrant(string $code, string $verifier = self::VERIFIER): array
    {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'https://claude.ai/oauth/mcp-oauth-client-metadata',
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'resource' => Config::mcpUrl(),
        ];
    }

    /** @return array<string, mixed> */
    private function exchange(): array
    {
        $reply = $this->token()->process($this->codeGrant($this->code()), '1.2.3.4');
        $this->assertSame(200, $reply['status']);

        return $reply['body'];
    }

    /** @return array<string, string> */
    private function refreshGrant(string $refresh): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => 'https://claude.ai/oauth/mcp-oauth-client-metadata',
        ];
    }

    public function testCodeExchangeIssuesWorkingTokens(): void
    {
        $body = $this->exchange();

        $this->assertSame('Bearer', $body['token_type']);
        $this->assertNotNull($this->store()->validateAccess((string) $body['access_token']));
    }

    public function testResponseIsNeverCached(): void
    {
        $reply = $this->token()->process($this->codeGrant($this->code()), '1.2.3.4');

        $this->assertSame('no-store', $reply['headers']['Cache-Control']);
    }

    public function testWrongVerifierIsRefusedAndBurnsTheCode(): void
    {
        $code = $this->code();

        $bad = $this->token()->process($this->codeGrant($code, str_repeat('a', 43)), '1.2.3.4');
        $retry = $this->token()->process($this->codeGrant($code), '1.2.3.4');

        $this->assertSame('invalid_grant', $bad['body']['error']);
        $this->assertSame('invalid_grant', $retry['body']['error']);
    }

    public function testReplayedCodeIsRefusedAndRevokesIssuedTokens(): void
    {
        $code = $this->code();
        $first = $this->token()->process($this->codeGrant($code), '1.2.3.4');
        $replay = $this->token()->process($this->codeGrant($code), '1.2.3.4');

        $this->assertSame('invalid_grant', $replay['body']['error']);
        $this->assertNull($this->store()->validateAccess((string) $first['body']['access_token']));
    }

    public function testParallelRedemptionHasOneWinner(): void
    {
        $code = $this->code();
        $id = (int) $this->store()->find('code', $code)['id'];

        $this->assertTrue($this->store()->consume($id));
        $this->assertSame('invalid_grant', $this->token()->process($this->codeGrant($code), '1.2.3.4')['body']['error']);
    }

    public function testExpiredCodeIsRefused(): void
    {
        $code = $this->code();
        $this->now += 61;

        $this->assertSame('invalid_grant', $this->token()->process($this->codeGrant($code), '1.2.3.4')['body']['error']);
    }

    public function testCodeIsBoundToRedirect(): void
    {
        $grant = ['redirect_uri' => 'https://claude.ai/other'] + $this->codeGrant($this->code());

        $this->assertSame('invalid_grant', $this->token()->process($grant, '1.2.3.4')['body']['error']);
    }

    public function testResourceMismatchIsRefused(): void
    {
        $grant = ['resource' => 'https://example.test/wp-json/wp/v2'] + $this->codeGrant($this->code());

        $this->assertSame('invalid_target', $this->token()->process($grant, '1.2.3.4')['body']['error']);
    }

    public function testUnsupportedGrantIsRefused(): void
    {
        $reply = $this->token()->process(['grant_type' => 'password'], '1.2.3.4');

        $this->assertSame('unsupported_grant_type', $reply['body']['error']);
    }

    public function testRefreshRotates(): void
    {
        $body = $this->exchange();

        $reply = $this->token()->process($this->refreshGrant((string) $body['refresh_token']), '1.2.3.4');

        $this->assertSame(200, $reply['status']);
        $this->assertNotSame($body['refresh_token'], $reply['body']['refresh_token']);
        $this->assertNotNull($this->store()->validateAccess((string) $reply['body']['access_token']));
    }

    public function testRetryWithinGraceIsAnswered(): void
    {
        $body = $this->exchange();
        $this->token()->process($this->refreshGrant((string) $body['refresh_token']), '1.2.3.4');
        $this->now += 30;

        $retry = $this->token()->process($this->refreshGrant((string) $body['refresh_token']), '1.2.3.4');

        $this->assertSame(200, $retry['status']);
    }

    public function testReuseAfterGraceRevokesFamilyAndLogs(): void
    {
        $body = $this->exchange();
        $rotated = $this->token()->process($this->refreshGrant((string) $body['refresh_token']), '1.2.3.4');
        $this->now += 31;

        $reuse = $this->token()->process($this->refreshGrant((string) $body['refresh_token']), '1.2.3.4');

        $this->assertSame('invalid_grant', $reuse['body']['error']);
        $this->assertNull($this->store()->validateAccess((string) $rotated['body']['access_token']));
        $this->assertSame('invalid_grant', $this->token()->process($this->refreshGrant((string) $rotated['body']['refresh_token']), '1.2.3.4')['body']['error']);
        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('wpsmcp: refresh_reuse', $log);
        $this->assertStringNotContainsString((string) $body['refresh_token'], $log);
    }

    public function testRefreshIsBoundToClient(): void
    {
        $body = $this->exchange();
        $grant = ['client_id' => 'https://other.example/meta'] + $this->refreshGrant((string) $body['refresh_token']);

        $this->assertSame('invalid_grant', $this->token()->process($grant, '1.2.3.4')['body']['error']);
    }

    public function testExpiredRefreshTokenIsRefused(): void
    {
        $body = $this->exchange();
        $this->now += Config::REFRESH_TTL + 1;

        $this->assertSame('invalid_grant', $this->token()->process($this->refreshGrant((string) $body['refresh_token']), '1.2.3.4')['body']['error']);
    }

    public function testRefreshLosesAccessWhenUserLosesRole(): void
    {
        $body = $this->exchange();
        \WpState::$canEdit[3] = false;

        $this->assertSame('invalid_grant', $this->token()->process($this->refreshGrant((string) $body['refresh_token']), '1.2.3.4')['body']['error']);
    }

    public function testFamilyIsThrottledWith429(): void
    {
        $body = $this->exchange();
        $reply = null;
        for ($i = 0; $i < 31; $i++) {
            $reply = $this->token()->process($this->refreshGrant((string) $body['refresh_token']), '1.2.3.4');
        }

        $this->assertNotNull($reply);
        $this->assertSame(429, $reply['status']);
        $this->assertSame('temporarily_unavailable', $reply['body']['error']);
        $this->assertSame('60', $reply['headers']['Retry-After']);
    }

    public function testThrottleIsKeyedByFamilyNotAddress(): void
    {
        $a = $this->exchange();
        $b = $this->exchange();
        for ($i = 0; $i < 31; $i++) {
            $this->token()->process($this->refreshGrant((string) $a['refresh_token']), '1.2.3.4');
        }

        $reply = $this->token()->process($this->refreshGrant((string) $b['refresh_token']), '1.2.3.4');

        $this->assertSame(200, $reply['status']);
    }
}
