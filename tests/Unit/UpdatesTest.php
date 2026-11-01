<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\Updates;

final class UpdatesTest extends WpTestCase
{
    private const PACKAGE = 'https://github.com/raaaf/wp-starter-mcp/releases/download/v1.2.0/wp-starter-mcp-1.2.0.zip';
    private const OURS = ['plugin' => Updates::PLUGIN_FILE];

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->setStatic('publicKeysOverride', [base64_encode(sodium_crypto_sign_publickey($pair))]);
        $this->setStatic('checkerFactory', null);
    }

    protected function tearDown(): void
    {
        $this->setStatic('publicKeysOverride', null);
        $this->setStatic('checkerFactory', null);
    }

    private function setStatic(string $name, mixed $value): void
    {
        $property = new \ReflectionProperty(Updates::class, $name);
        $property->setValue(null, $value);
    }

    /** Serves a release: the zip body plus optional checksum and signature assets. */
    private function release(string $zip, ?string $checksum, ?string $signature): void
    {
        \WpState::$downloadBody = $zip;
        if ($checksum !== null) {
            \WpState::$remote[self::PACKAGE . '.sha256'] = [200, $checksum . '  wp-starter-mcp-1.2.0.zip'];
        }
        if ($signature !== null) {
            \WpState::$remote[self::PACKAGE . '.sig'] = [200, $signature];
        }
    }

    private function sign(string $zip): string
    {
        return base64_encode(sodium_crypto_sign_detached($zip, $this->secret));
    }

    public function testCheckerIsBuiltOutsideAdminToo(): void
    {
        $built = 0;
        $this->setStatic('checkerFactory', static function () use (&$built): void {
            $built++;
        });

        Updates::register();

        $this->assertSame(1, $built);
    }

    public function testAutoUpdateOnlyForThisPluginAndOnlyWhileOn(): void
    {
        $ours = (object) ['plugin' => Updates::PLUGIN_FILE];
        $other = (object) ['plugin' => 'akismet/akismet.php'];

        $this->assertFalse(Updates::autoUpdate(false, $ours));

        \WpState::$options['wpsmcp_enabled'] = '1';
        $this->assertTrue(Updates::autoUpdate(false, $ours));
        $this->assertFalse(Updates::autoUpdate(false, $other));
    }

    public function testValidChecksumAndSignatureAreAccepted(): void
    {
        $this->release('zip-bytes', hash('sha256', 'zip-bytes'), $this->sign('zip-bytes'));

        $result = Updates::verifyPackage(false, self::PACKAGE, null, self::OURS);

        $this->assertIsString($result);
        $this->assertSame('zip-bytes', file_get_contents($result));
    }

    public function testWrongChecksumIsRefused(): void
    {
        $this->release('zip-bytes', hash('sha256', 'other'), $this->sign('zip-bytes'));

        $this->assertSame('wpsmcp_update_checksum_mismatch', $this->refusal());
    }

    public function testMissingChecksumIsRefused(): void
    {
        $this->release('zip-bytes', null, $this->sign('zip-bytes'));

        $this->assertSame('wpsmcp_update_checksum_missing', $this->refusal());
    }

    public function testMissingSignatureIsRefused(): void
    {
        $this->release('zip-bytes', hash('sha256', 'zip-bytes'), null);

        $this->assertSame('wpsmcp_update_signature_missing', $this->refusal());
    }

    public function testSignatureByAnotherKeyIsRefused(): void
    {
        $foreign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->release('zip-bytes', hash('sha256', 'zip-bytes'), base64_encode(sodium_crypto_sign_detached('zip-bytes', $foreign)));

        $this->assertSame('wpsmcp_update_signature_invalid', $this->refusal());
    }

    public function testEmptyKeyListRefusesEveryUpdate(): void
    {
        $this->setStatic('publicKeysOverride', []);
        $this->release('zip-bytes', hash('sha256', 'zip-bytes'), $this->sign('zip-bytes'));

        $this->assertSame('wpsmcp_update_no_signing_key', $this->refusal());
    }

    public function testOtherPackagesAreNotTouched(): void
    {
        $result = Updates::verifyPackage(false, 'https://example.test/other.zip', null, ['plugin' => 'akismet/akismet.php']);

        $this->assertFalse($result);
        $this->assertSame([], \WpState::$fetched);
    }

    private function refusal(): string
    {
        $result = Updates::verifyPackage(false, self::PACKAGE, null, self::OURS);
        $this->assertInstanceOf(\WP_Error::class, $result);

        return $result->get_error_code();
    }

    public function testBelowPublishedMinimumFailsClosed(): void
    {
        \WpState::$remote[Updates::MANIFEST_URL] = [200, '{"minimum":"9.0.0"}'];

        $this->assertFalse(Updates::allowsSwitchOn());
        $this->assertTrue(Updates::belowMinimum());
    }

    public function testUnreachableManifestChangesNothing(): void
    {
        \WpState::$options['wpsmcp_min_version'] = '0.5.0';

        Updates::refreshManifest();

        $this->assertSame('0.5.0', \WpState::$options['wpsmcp_min_version']);
        $this->assertFalse(Updates::belowMinimum());
        $this->assertTrue(Updates::allowsSwitchOn());
    }

    public function testMalformedManifestChangesNothing(): void
    {
        \WpState::$remote[Updates::MANIFEST_URL] = [200, '<html>nope</html>'];

        Updates::refreshManifest();

        $this->assertArrayNotHasKey('wpsmcp_min_version', \WpState::$options);
    }
}
