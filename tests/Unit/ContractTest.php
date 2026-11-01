<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use WpStarterMcp\BearerGate;
use WpStarterMcp\Contract;
use WpStarterMcp\Server;
use WpStarterMcp\OAuth\TokenStore;

final class ContractTest extends WpTestCase
{
    public function testValidContractIsUsable(): void
    {
        $this->assertNotNull(Contract::current());
        $this->assertSame('', Contract::problem());
    }

    public function testNoAnswerFromTheThemeFailsClosedWithReason(): void
    {
        \WpState::$contract = null;

        $this->assertNull(Contract::current());
        $this->assertSame('no_contract', Contract::problem());
        $this->assertNotSame('', Contract::message());
    }

    public function testUnknownVersionFailsClosed(): void
    {
        \WpState::$contract = ['version' => 2] + $this->validContract();

        $this->assertNull(Contract::current());
        $this->assertSame('unknown_version', Contract::problem());
    }

    /** @return array<string, array{string}> */
    public static function brokenKeys(): array
    {
        return [
            'page field' => ['page_field'], 'field key' => ['page_field_key'], 'writable' => ['writable'],
            'copy meta' => ['copy_meta'], 'layouts' => ['layouts'], 'is_readable' => ['is_readable'],
            'after_write' => ['after_write'], 'embed host' => ['is_allowed_embed_host'],
            'design tokens' => ['design_tokens'], 'validate' => ['validate_sections'],
        ];
    }

    #[DataProvider('brokenKeys')]
    public function testMissingKeyFailsClosed(string $key): void
    {
        $contract = $this->validContract();
        unset($contract[$key]);
        \WpState::$contract = $contract;

        $this->assertNull(Contract::current());
        $this->assertSame('invalid', Contract::problem());
    }

    public function testCallbackThatIsNotCallableFailsClosed(): void
    {
        \WpState::$contract = ['is_readable' => 'no_such_function'] + $this->validContract();

        $this->assertNull(Contract::current());
    }

    public function testThrowingCallbackDeniesInsteadOfCrashing(): void
    {
        \WpState::$contract = ['is_readable' => static function (int $id): bool {
            throw new \RuntimeException('boom');
        }] + $this->validContract();

        $this->assertFalse($this->contract()->isReadable(10));
    }

    public function testFailingSectionCheckIsAProblem(): void
    {
        \WpState::$contract = ['validate_sections' => static fn (array $s): mixed => null] + $this->validContract();

        $this->assertNotSame([], $this->contract()->validateSections([]));
    }

    public function testNegativeAnswerBeforeThemeSetupIsNotCached(): void
    {
        \WpState::$afterSetupTheme = 0;
        $this->assertNull(Contract::current());

        \WpState::$afterSetupTheme = 1;
        $this->assertNotNull(Contract::current());
    }

    public function testTokenDoesNotAuthenticateWithoutContract(): void
    {
        $this->allowUser(3);
        $token = (new TokenStore())->issue('access', 3600, ['user_id' => 3, 'family_id' => 'f1', 'client_id' => 'c', 'scope' => 'mcp:read']);
        $_SERVER['REQUEST_URI'] = '/wp-json/wpsmcp/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        \WpState::$contract = null;

        $this->assertFalse(BearerGate::determineUser(false));
    }

    public function testServerIsNotCreatedWithoutContract(): void
    {
        \WpState::$contract = null;
        $adapter = new class () {
            public int $created = 0;

            public function create_server(mixed ...$args): void
            {
                $this->created++;
            }
        };

        Server::create($adapter);

        $this->assertSame(0, $adapter->created);
    }

    public function testServerOffersExactlyTheListedAbilities(): void
    {
        $adapter = new class () {
            /** @var list<mixed> */
            public array $tools = [];

            public function create_server(mixed ...$args): void
            {
                $this->tools = $args[9];
            }
        };

        Server::create($adapter);

        $this->assertSame(\WpStarterMcp\Abilities::NAMES, $adapter->tools);
    }
}
