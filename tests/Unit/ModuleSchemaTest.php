<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

final class ModuleSchemaTest extends WpTestCase
{
    public function testOnlyWritableLayoutsAreOffered(): void
    {
        $names = array_column($this->schema()->summary(), 'name');

        $this->assertContains('hero', $names);
        $this->assertNotContains('newsletter', $names);
        $this->assertNull($this->schema()->compact('newsletter'));
    }

    public function testWritableNameThatTheThemeDoesNotRegisterIsAbsent(): void
    {
        $contract = $this->validContract();
        $contract['writable'][] = 'ghost';
        \WpState::$contract = $contract;
        \WpStarterMcp\Contract::reset();

        $this->assertNull($this->schema()->compact('ghost'));
    }

    public function testSimpleLayoutListsFieldsWithTypesAndSkipsTabs(): void
    {
        $compact = $this->schema()->compact('hero');
        $fields = array_column((array) $compact['fields'], null, 'name');

        $this->assertArrayNotHasKey('', $fields);
        $this->assertSame('text', $fields['title']['type']);
        $this->assertTrue($fields['title']['required']);
        $this->assertSame('html', $fields['copy']['type']);
        $this->assertSame('link', $fields['cta']['type']);
        $this->assertSame('boolean', $fields['show']['type']);
    }

    public function testChoicesAndNumberLimitsAreExposed(): void
    {
        $fields = array_column((array) $this->schema()->compact('hero')['fields'], null, 'name');

        $this->assertSame(['centered', 'split'], $fields['variant']['choices']);
        $this->assertSame(20, $fields['overlay']['min']);
        $this->assertSame(100, $fields['overlay']['max']);
    }

    public function testNestedRepeaterAndModulesAreDescribed(): void
    {
        $tabs = array_column((array) $this->schema()->compact('tabs')['fields'], null, 'name')['tabs'];
        $modules = array_column($tabs['fields'], null, 'name')['modules'];

        $this->assertSame('rows', $tabs['type']);
        $this->assertSame('modules', $modules['type']);
    }

    public function testNestedLayoutOutsideTheAllowlistIsDroppedFromModules(): void
    {
        $tabs = array_column((array) $this->schema()->compact('tabs')['fields'], null, 'name')['tabs'];
        $modules = array_column($tabs['fields'], null, 'name')['modules'];

        $this->assertSame(['note'], array_column($modules['modules'], 'name'));
    }

    public function testUnknownFieldTypeIsNotOfferedButStaysUnsupported(): void
    {
        $fields = array_column((array) $this->schema()->compact('hero')['fields'], null, 'name');

        $this->assertArrayNotHasKey('date', $fields);
        $this->assertSame('unsupported', array_column((array) $this->schema()->find('hero')['fields'], null, 'name')['date']['kind']);
    }
}
