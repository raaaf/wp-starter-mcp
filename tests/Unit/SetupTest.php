<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\Intent;
use WpStarterMcp\Setup;
use WpStarterMcp\Updates;

final class SetupTest extends WpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \WpState::$currentCaps = ['edit_pages', 'manage_options'];
    }

    public function testSwitchOnNeedsBothAcknowledgements(): void
    {
        $this->assertSame('need_ack', Setup::enable(true, false));
        $this->assertSame('need_ack', Setup::enable(false, true));
        $this->assertArrayNotHasKey('wpsmcp_enabled', \WpState::$options);
    }

    public function testSwitchOnWithAcknowledgementsEnables(): void
    {
        $this->assertSame('enabled', Setup::enable(true, true));
        $this->assertSame('1', \WpState::$options['wpsmcp_enabled']);
    }

    public function testSwitchOnRefusedBelowMinimumVersion(): void
    {
        \WpState::$remote[Updates::MANIFEST_URL] = [200, '{"minimum":"9.0.0"}'];

        $this->assertSame('below_min', Setup::enable(true, true));
        $this->assertArrayNotHasKey('wpsmcp_enabled', \WpState::$options);
    }

    public function testEditorCannotSwitch(): void
    {
        \WpState::$currentCaps = ['edit_pages'];

        $this->assertSame('denied', Setup::enable(true, true));
        $this->assertSame('denied', Setup::disable());
    }

    public function testStartCreatesIntentOnlyWhileOn(): void
    {
        $this->assertSame('off', Setup::start());
        $this->assertFalse((new Intent())->isFresh(3));

        \WpState::$options['wpsmcp_enabled'] = '1';
        $this->assertSame('started', Setup::start());
        $this->assertTrue((new Intent())->isFresh(3));
    }

    public function testEditorRevokesOnlyOwnConnection(): void
    {
        $this->allowUser(3);
        $this->allowUser(4);
        $store = $this->store();
        foreach ([3 => 'f3', 4 => 'f4'] as $user => $family) {
            $store->issue('refresh', 3600, ['user_id' => $user, 'family_id' => $family, 'client_id' => 'https://claude.ai/x', 'scope' => 'mcp:read']);
        }
        \WpState::$currentCaps = ['edit_pages'];

        $this->assertSame('denied', Setup::revoke('f4'));
        $this->assertSame('revoked', Setup::revoke('f3'));
    }
}
