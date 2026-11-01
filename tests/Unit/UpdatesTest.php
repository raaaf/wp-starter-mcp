<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\Updates;

final class UpdatesTest extends WpTestCase
{
    private const PACKAGE = 'https://github.com/raaaf/wp-starter-mcp/releases/download/v1.2.0/wp-starter-mcp-1.2.0.zip';
    private const OURS = ['plugin' => Updates::PLUGIN_FILE];

    private string $secret = '';

    /** @var array<string, string> built once per test: zip headers carry a timestamp, so equal calls could differ */
    private array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->zips = [];
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->setStatic('publicKeysOverride', [base64_encode(sodium_crypto_sign_publickey($pair))]);
        $this->setStatic('checkerFactory', null);
        $this->setStatic('fileOverride', null);
    }

    protected function tearDown(): void
    {
        $this->setStatic('publicKeysOverride', null);
        $this->setStatic('checkerFactory', null);
        $this->setStatic('fileOverride', null);
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

    /** A release zip as the packager builds it: the main file sits in a plugin folder and carries the Version header. */
    private function zip(string $version = '1.2.0', string $entry = Updates::PLUGIN_FILE): string
    {
        return $this->zips[$version . $entry] ??= $this->buildZip($version, $entry);
    }

    private function buildZip(string $version, string $entry): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'wpsmcp-zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString($entry, "<?php\n/**\n * Plugin Name:       WP Starter MCP\n * Version:           {$version}\n */\n");
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
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
        $this->release($this->zip(), hash('sha256', $this->zip()), $this->sign($this->zip()));

        $result = Updates::verifyPackage(false, self::PACKAGE, null, self::OURS);

        $this->assertIsString($result);
        $this->assertSame($this->zip(), file_get_contents($result));
    }

    public function testWrongChecksumIsRefused(): void
    {
        $this->release($this->zip(), hash('sha256', 'other'), $this->sign($this->zip()));

        $this->assertSame('wpsmcp_update_checksum_mismatch', $this->refusal());
    }

    public function testMissingChecksumIsRefused(): void
    {
        $this->release($this->zip(), null, $this->sign($this->zip()));

        $this->assertSame('wpsmcp_update_checksum_missing', $this->refusal());
    }

    public function testMissingSignatureIsRefused(): void
    {
        $this->release($this->zip(), hash('sha256', $this->zip()), null);

        $this->assertSame('wpsmcp_update_signature_missing', $this->refusal());
    }

    public function testSignatureByAnotherKeyIsRefused(): void
    {
        $foreign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->release($this->zip(), hash('sha256', $this->zip()), base64_encode(sodium_crypto_sign_detached($this->zip(), $foreign)));

        $this->assertSame('wpsmcp_update_signature_invalid', $this->refusal());
    }

    public function testEmptyKeyListRefusesEveryUpdate(): void
    {
        $this->setStatic('publicKeysOverride', []);
        $this->release($this->zip(), hash('sha256', $this->zip()), $this->sign($this->zip()));

        $this->assertSame('wpsmcp_update_no_signing_key', $this->refusal());
    }

    /** @return array<string, array{string, string}> */
    public static function staleVersions(): array
    {
        return [
            'same version' => ['1.0.0', Updates::PLUGIN_FILE],
            'older version, validly signed' => ['0.9.0', Updates::PLUGIN_FILE],
            'main file missing' => ['9.9.9', 'wp-starter-mcp/other.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staleVersions')]
    public function testSignedPackageThatIsNotNewerIsRefused(string $version, string $entry): void
    {
        $zip = $this->zip($version, $entry);
        $this->release($zip, hash('sha256', $zip), $this->sign($zip));

        $this->assertSame('wpsmcp_update_not_newer', $this->refusal());
    }

    public function testPackageThatIsNoZipIsRefused(): void
    {
        $this->release('not a zip', hash('sha256', 'not a zip'), $this->sign('not a zip'));

        $this->assertSame('wpsmcp_update_not_newer', $this->refusal());
    }

    public function testRenamedPluginFolderIsStillVerified(): void
    {
        $this->setStatic('fileOverride', '/srv/wp-content/plugins/starter-mcp-main/wp-starter-mcp.php');
        $this->release($this->zip(), hash('sha256', 'other'), $this->sign($this->zip()));

        $result = Updates::verifyPackage(false, self::PACKAGE, null, ['plugin' => 'starter-mcp-main/wp-starter-mcp.php']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpsmcp_update_checksum_mismatch', $result->get_error_code());
    }

    public function testRenamedPluginFolderStillAutoUpdatesWhileOn(): void
    {
        $this->setStatic('fileOverride', '/srv/wp-content/plugins/starter-mcp-main/wp-starter-mcp.php');
        \WpState::$options['wpsmcp_enabled'] = '1';

        $this->assertTrue(Updates::autoUpdate(false, (object) ['plugin' => 'starter-mcp-main/wp-starter-mcp.php']));
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
