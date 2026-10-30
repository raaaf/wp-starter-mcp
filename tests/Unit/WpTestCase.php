<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpStarterMcp\OAuth\TokenStore;

abstract class WpTestCase extends TestCase
{
    protected int $now = 1_800_000_000;

    protected function setUp(): void
    {
        \WpState::reset();
        ini_set('error_log', '/dev/null');
        $_SERVER = [];
        $this->now = 1_800_000_000;
    }

    protected function store(): TokenStore
    {
        return new TokenStore(fn (): int => $this->now);
    }

    protected function allowUser(int $id): void
    {
        \WpState::$canEdit[$id] = true;
        \WpState::$users[$id] = (object) ['ID' => $id, 'user_email' => "u{$id}@example.test", 'display_name' => "User {$id}", 'user_pass' => 'hash'];
    }

    /** @return array<string, string> */
    protected function authParams(string $challenge): array
    {
        return [
            'response_type' => 'code',
            'client_id' => 'https://claude.ai/oauth/mcp-oauth-client-metadata',
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => \WpStarterMcp\Config::mcpUrl(),
            'scope' => 'mcp:read mcp:draft',
            'state' => 'xyz',
        ];
    }

    protected function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
