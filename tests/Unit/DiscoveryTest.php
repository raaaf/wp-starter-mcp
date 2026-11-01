<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\OAuth\Discovery;

final class DiscoveryTest extends WpTestCase
{
    public function testServerDocumentAdvertisesCimdPkceAndNoRegistration(): void
    {
        $doc = Discovery::match('/.well-known/oauth-authorization-server');

        $this->assertNotNull($doc);
        $this->assertTrue($doc['client_id_metadata_document_supported']);
        $this->assertSame(['S256'], $doc['code_challenge_methods_supported']);
        $this->assertSame(['none'], $doc['token_endpoint_auth_methods_supported']);
        $this->assertArrayNotHasKey('registration_endpoint', $doc);
    }

    public function testResourceDocumentNamesTheExactMcpUrl(): void
    {
        $doc = Discovery::match('/.well-known/oauth-protected-resource/wp-json/wpsmcp/v1/mcp');

        $this->assertNotNull($doc);
        $this->assertSame('https://example.test/wp-json/wpsmcp/v1/mcp', $doc['resource']);
    }

    public function testResourceDocumentForOtherPathIsNotServed(): void
    {
        $this->assertNull(Discovery::match('/.well-known/oauth-protected-resource/wp-json/wp/v2'));
    }

    public function testSubdirectoryInstallUsesPathInsertedUrls(): void
    {
        \WpState::$home = 'https://example.test/blog';

        $this->assertNotNull(Discovery::match('/.well-known/oauth-protected-resource/blog/wp-json/wpsmcp/v1/mcp'));
        $this->assertNotNull(Discovery::match('/.well-known/oauth-authorization-server/blog'));
        $this->assertNull(Discovery::match('/.well-known/oauth-authorization-server/other'));
    }

    public function testUnrelatedPathIsIgnored(): void
    {
        $this->assertNull(Discovery::match('/about'));
    }
}
