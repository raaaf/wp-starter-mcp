<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpStarterMcp\OAuth\ClientMap;

final class ClientMapTest extends TestCase
{
    private const CLAUDE_CODE = 'https://claude.ai/oauth/claude-code-client-metadata';
    private const WEB = 'https://claude.ai/oauth/mcp-oauth-client-metadata';

    public function testClaudeCodeLoopbackAcceptsAnyPort(): void
    {
        $this->assertTrue(ClientMap::allows(self::CLAUDE_CODE, 'http://localhost:53999/callback'));
        $this->assertTrue(ClientMap::allows(self::CLAUDE_CODE, 'http://127.0.0.1:8080/callback'));
        $this->assertTrue(ClientMap::allows(self::CLAUDE_CODE, 'http://localhost/callback'));
    }

    public function testClaudeCodeRefusesAnythingElse(): void
    {
        foreach ([
            'https://localhost:53999/callback',
            'http://localhost.evil.example:53999/callback',
            'http://localhost:53999@evil.example/callback',
            'http://evil.example:53999/callback',
            'http://localhost:53999/other',
            'http://localhost:53999/callback?x=1',
            'http://localhost:53999/callback#x',
            'http://localhost:99999/callback',
        ] as $redirect) {
            $this->assertFalse(ClientMap::allows(self::CLAUDE_CODE, $redirect), $redirect);
        }
    }

    public function testLoopbackPortRuleDoesNotApplyToTheWebClient(): void
    {
        $this->assertTrue(ClientMap::allows(self::WEB, 'https://claude.ai/api/mcp/auth_callback'));
        $this->assertFalse(ClientMap::allows(self::WEB, 'http://localhost:53999/callback'));
    }
}
