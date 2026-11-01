<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use WpStarterMcp\Config;
use WpStarterMcp\Intent;
use WpStarterMcp\OAuth\Authorize;

final class AuthorizeTest extends WpTestCase
{
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private function authorize(): Authorize
    {
        return new Authorize($this->store(), new Intent(fn (): int => $this->now));
    }

    /** @return array<string, string> */
    private function post(string $decision = 'allow'): array
    {
        $params = $this->authParams(self::CHALLENGE);
        $request = Authorize::validate($params);
        $this->assertIsArray($request);
        $action = 'wpsmcp_consent_' . hash('sha256', (string) json_encode($request));

        return $params + ['decision' => $decision, '_wpnonce' => 'nonce-' . $action];
    }

    private function withIntent(): void
    {
        $this->allowUser(3);
        (new Intent(fn (): int => $this->now))->create(3);
    }

    /** @return array<string, array{string, string}> */
    public static function rejectedRequests(): array
    {
        return [
            'unknown client' => ['client_id', 'https://evil.example/meta'],
            'redirect with path suffix' => ['redirect_uri', 'https://claude.ai/api/mcp/auth_callback/'],
            'redirect on other host' => ['redirect_uri', 'https://claude.ai.evil.example/api/mcp/auth_callback'],
            'redirect with query' => ['redirect_uri', 'https://claude.ai/api/mcp/auth_callback?x=1'],
            'plain PKCE' => ['code_challenge_method', 'plain'],
            'no PKCE method' => ['code_challenge_method', ''],
            'short challenge' => ['code_challenge', 'abc'],
            'token response type' => ['response_type', 'token'],
            'other resource' => ['resource', 'https://example.test/wp-json/wp/v2'],
            'unknown scope' => ['scope', 'mcp:read admin'],
        ];
    }

    #[DataProvider('rejectedRequests')]
    public function testInvalidRequestRendersLocalErrorAndNeverRedirects(string $key, string $value): void
    {
        $this->withIntent();
        $params = [$key => $value] + $this->authParams(self::CHALLENGE);

        $result = $this->authorize()->process('GET', $params, 3, true);

        $this->assertSame('error', $result['view']);
        $this->assertArrayNotHasKey('url', $result);
    }

    public function testInvalidRequestIsRefusedBeforeLogin(): void
    {
        $params = ['client_id' => 'x'] + $this->authParams(self::CHALLENGE);

        $this->assertSame('error', $this->authorize()->process('GET', $params, 0, false)['view']);
    }

    public function testAbsentResourceIsBoundToMcpUrl(): void
    {
        $params = ['resource' => ''] + $this->authParams(self::CHALLENGE);

        $request = Authorize::validate($params);

        $this->assertIsArray($request);
        $this->assertSame(Config::mcpUrl(), $request['resource']);
    }

    public function testAnonymousUserIsSentToLogin(): void
    {
        $this->assertSame('login', $this->authorize()->process('GET', $this->authParams(self::CHALLENGE), 0, false)['view']);
    }

    public function testUserWithoutEditPagesIsRefused(): void
    {
        $result = $this->authorize()->process('GET', $this->authParams(self::CHALLENGE), 3, false);

        $this->assertSame('error', $result['view']);
        $this->assertSame(403, $result['status']);
    }

    public function testMissingIntentShowsIntentPage(): void
    {
        $this->allowUser(3);

        $this->assertSame('intent', $this->authorize()->process('GET', $this->authParams(self::CHALLENGE), 3, true)['view']);
    }

    public function testStaleIntentShowsIntentPage(): void
    {
        $this->withIntent();
        $this->now += 601;

        $this->assertSame('intent', $this->authorize()->process('GET', $this->authParams(self::CHALLENGE), 3, true)['view']);
    }

    public function testFreshIntentShowsConsent(): void
    {
        $this->withIntent();

        $this->assertSame('consent', $this->authorize()->process('GET', $this->authParams(self::CHALLENGE), 3, true)['view']);
    }

    public function testPostWithWrongNonceIsRefusedWithoutCode(): void
    {
        $this->withIntent();
        $params = ['_wpnonce' => 'forged'] + $this->post();

        $result = $this->authorize()->process('POST', $params, 3, true);

        $this->assertSame('error', $result['view']);
        $this->assertSame([], $GLOBALS['wpdb']->tables);
    }

    public function testNonceIsBoundToTheParameters(): void
    {
        $this->withIntent();
        $params = ['state' => 'other'] + $this->post();

        $this->assertSame('error', $this->authorize()->process('POST', $params, 3, true)['view']);
    }

    public function testAllowRedirectsWithCodeAndStateAndMailsTheUser(): void
    {
        $this->withIntent();

        $result = $this->authorize()->process('POST', $this->post(), 3, true);

        $this->assertSame('redirect', $result['view']);
        $this->assertStringStartsWith('https://claude.ai/api/mcp/auth_callback?code=', (string) $result['url']);
        $this->assertStringContainsString('state=xyz', (string) $result['url']);
        $this->assertCount(1, \WpState::$mails);
        $this->assertSame('u3@example.test', \WpState::$mails[0]['to']);
        parse_str((string) parse_url((string) $result['url'], PHP_URL_QUERY), $query);
        $this->assertStringNotContainsString((string) $query['code'], \WpState::$mails[0]['message']);
    }

    public function testAllowConsumesTheIntent(): void
    {
        $this->withIntent();
        $this->authorize()->process('POST', $this->post(), 3, true);

        $this->assertSame('intent', $this->authorize()->process('POST', $this->post(), 3, true)['view']);
    }

    public function testDenyRedirectsWithAccessDeniedAndIssuesNoCode(): void
    {
        $this->withIntent();

        $result = $this->authorize()->process('POST', $this->post('deny'), 3, true);

        $this->assertStringContainsString('error=access_denied', (string) $result['url']);
        $this->assertSame([], \WpState::$mails);
        $this->assertSame([], $GLOBALS['wpdb']->tables);
    }
}
