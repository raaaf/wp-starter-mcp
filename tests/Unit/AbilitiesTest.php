<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\Abilities;
use WpStarterMcp\Contract;

final class AbilitiesTest extends WpTestCase
{
    private function tokenAuthenticated(string $scope): void
    {
        $this->allowUser(3);
        $token = (new \WpStarterMcp\OAuth\TokenStore())->issue('access', 3600, ['user_id' => 3, 'family_id' => 'fam1', 'client_id' => 'https://claude.ai/oauth/mcp-oauth-client-metadata', 'scope' => $scope]);
        $_SERVER['REQUEST_URI'] = '/wp-json/wpsmcp/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        \WpStarterMcp\BearerGate::determineUser(false);
    }

    public function testNoContractRefusesEverything(): void
    {
        \WpState::$contract = null;

        $this->assertInstanceOf(\WP_Error::class, Abilities::permit(Abilities::READ, [], null));
    }

    public function testMissingCapabilityIsRefused(): void
    {
        \WpState::$currentCaps = [];

        $this->assertSame('wpsmcp_forbidden', Abilities::permit(Abilities::READ, [], null)->get_error_code());
    }

    public function testReadOnlyTokenCannotWrite(): void
    {
        $this->tokenAuthenticated('mcp:read');

        $this->assertTrue(Abilities::permit(Abilities::READ, [], null));
        $this->assertSame('wpsmcp_scope', Abilities::permit(Abilities::DRAFT, [], null)->get_error_code());
    }

    public function testTargetPageNeedsEditPostOnIt(): void
    {
        \WpState::$currentCaps = ['edit_pages'];

        $this->assertSame('wpsmcp_page', Abilities::permit(Abilities::DRAFT, ['id' => 10], 'edit')->get_error_code());
    }

    public function testPageThatTheContractDoesNotConsiderReadableIsRefused(): void
    {
        $this->assertSame('wpsmcp_page', Abilities::permit(Abilities::DRAFT, ['id' => 999], 'edit')->get_error_code());
        $this->assertTrue(Abilities::permit(Abilities::DRAFT, ['id' => 10], 'edit'));
    }

    public function testPublishedPageCannotBeUpdatedAndPointsToTheCopyTool(): void
    {
        \WpState::$posts[10] = (object) ['ID' => 10, 'post_status' => 'publish', 'post_type' => 'page'];

        $result = Abilities::updateDraftPage(['id' => 10, 'sections' => []]);

        $this->assertSame('wpsmcp_not_draft', $result->get_error_code());
        $this->assertStringContainsString('create-draft-copy', $result->get_error_message());
        $this->assertArrayNotHasKey(10, \WpState::$fields);
    }

    public function testPendingPageCannotBeUpdated(): void
    {
        \WpState::$posts[10] = (object) ['ID' => 10, 'post_status' => 'pending', 'post_type' => 'page'];

        $this->assertSame('wpsmcp_not_draft', Abilities::updateDraftPage(['id' => 10, 'sections' => []])->get_error_code());
    }

    public function testInvalidSectionsAreNotSavedAndListEveryProblem(): void
    {
        \WpState::$posts[10] = (object) ['ID' => 10, 'post_status' => 'draft', 'post_type' => 'page', 'post_title' => 'x'];
        \WpState::$fields[10] = [['acf_fc_layout' => 'hero', 'title' => 'old']];

        $result = Abilities::updateDraftPage(['id' => 10, 'sections' => [['acf_fc_layout' => 'newsletter'], ['acf_fc_layout' => 'hero', 'nope' => 1]]]);

        $this->assertSame('wpsmcp_invalid', $result->get_error_code());
        $this->assertStringContainsString('newsletter', $result->get_error_message());
        $this->assertStringContainsString('nope', $result->get_error_message());
        $this->assertSame([['acf_fc_layout' => 'hero', 'title' => 'old']], \WpState::$fields[10]);
    }

    public function testValidDraftUpdateWritesAndReturnsTheLinks(): void
    {
        \WpState::$posts[10] = (object) ['ID' => 10, 'post_status' => 'draft', 'post_type' => 'page', 'post_title' => 'x'];

        $result = Abilities::updateDraftPage(['id' => 10, 'sections' => [['acf_fc_layout' => 'note', 'text' => 'Hello']]]);

        $this->assertSame([['acf_fc_layout' => 'note', 'text' => 'Hello']], \WpState::$fields[10]);
        $this->assertStringContainsString('action=edit', $result['edit_url']);
        $this->assertStringContainsString('preview=true', $result['preview_url']);
    }

    public function testKeepRowsSurviveAnUpdate(): void
    {
        \WpState::$posts[10] = (object) ['ID' => 10, 'post_status' => 'draft', 'post_type' => 'page', 'post_title' => 'x'];
        \WpState::$fields[10] = [['acf_fc_layout' => 'newsletter', 'action' => 'https://x.example']];
        $keep = (new \WpStarterMcp\PageContent($this->contract(), $this->schema()))->forClaude(10);

        Abilities::updateDraftPage(['id' => 10, 'sections' => array_merge($keep, [['acf_fc_layout' => 'note', 'text' => 'new']])]);

        $this->assertSame([['acf_fc_layout' => 'newsletter', 'action' => 'https://x.example'], ['acf_fc_layout' => 'note', 'text' => 'new']], \WpState::$fields[10]);
    }

    public function testCreateNeverPublishes(): void
    {
        $result = Abilities::createDraftPage(['title' => 'New', 'sections' => [['acf_fc_layout' => 'note', 'text' => 'x']]]);

        $this->assertSame('draft', \WpState::$posts[$result['id']]->post_status);
    }

    public function testCreateNeedsATitle(): void
    {
        $this->assertSame('wpsmcp_title', Abilities::createDraftPage(['title' => ' ', 'sections' => []])->get_error_code());
    }

    public function testCreateWithInvalidSectionsCreatesNoPage(): void
    {
        Abilities::createDraftPage(['title' => 'New', 'sections' => [['acf_fc_layout' => 'newsletter']]]);

        $this->assertSame([], array_filter(array_keys(\WpState::$posts), static fn (int $id): bool => $id >= 100));
    }

    public function testWriteQuotaIsEnforcedPerConnection(): void
    {
        $this->tokenAuthenticated('mcp:read mcp:draft');
        $bad = ['title' => 'x', 'sections' => [['acf_fc_layout' => 'newsletter']]];

        for ($i = 0; $i < Abilities::WRITES_PER_HOUR; $i++) {
            $this->assertSame('wpsmcp_invalid', Abilities::createDraftPage($bad)->get_error_code());
        }

        $this->assertSame('wpsmcp_quota', Abilities::createDraftPage($bad)->get_error_code());
    }

    public function testQuotaOfOneConnectionDoesNotBlockAnother(): void
    {
        $this->tokenAuthenticated('mcp:read mcp:draft');
        $bad = ['title' => 'x', 'sections' => [['acf_fc_layout' => 'newsletter']]];
        for ($i = 0; $i <= Abilities::WRITES_PER_HOUR; $i++) {
            Abilities::createDraftPage($bad);
        }

        \WpStarterMcp\BearerGate::forgetToken();

        $this->assertSame('wpsmcp_invalid', Abilities::createDraftPage($bad)->get_error_code());
    }

    public function testCopyKeepsEverySectionTemplateAndSeoMetaAndRecordsTheOriginal(): void
    {
        \WpState::$posts[10] = (object) ['ID' => 10, 'post_status' => 'publish', 'post_type' => 'page', 'post_title' => 'About', 'post_content' => '', 'post_modified_gmt' => '2026-10-01 10:00:00'];
        $rows = [['acf_fc_layout' => 'newsletter', 'action' => 'x'], ['acf_fc_layout' => 'hero', 'title' => 'Hi']];
        \WpState::$fields[10] = $rows;
        \WpState::$meta[10] = ['_wp_page_template' => 'tpl.php', '_thumbnail_id' => '50', '_yoast_wpseo_title' => 'SEO'];

        $result = Abilities::createDraftCopy(['id' => 10]);

        $id = $result['id'];
        $this->assertSame($rows, \WpState::$fields[$id]);
        $this->assertSame('draft', \WpState::$posts[$id]->post_status);
        $this->assertSame('About (Kopie)', \WpState::$posts[$id]->post_title);
        $this->assertSame('tpl.php', \WpState::$meta[$id]['_wp_page_template']);
        $this->assertSame('SEO', \WpState::$meta[$id]['_yoast_wpseo_title']);
        $this->assertSame('50', \WpState::$meta[$id]['_thumbnail_id']);
        $this->assertSame(10, \WpState::$meta[$id]['_wpsmcp_copy_of']);
        $this->assertSame('2026-10-01 10:00:00', \WpState::$meta[$id]['_wpsmcp_copy_modified_gmt']);
        $this->assertSame('publish', \WpState::$posts[10]->post_status);
    }

    public function testCopyKeepsBackslashesInContentTitleAndMeta(): void
    {
        \WpState::$posts[10] = (object) ['ID' => 10, 'post_status' => 'publish', 'post_type' => 'page', 'post_title' => 'C:\\new', 'post_content' => 'a\\b', 'post_modified_gmt' => ''];
        \WpState::$fields[10] = [];
        \WpState::$meta[10] = ['_yoast_wpseo_title' => 'x\\y'];

        $id = Abilities::createDraftCopy(['id' => 10])['id'];

        $this->assertSame('a\\b', \WpState::$posts[$id]->post_content);
        $this->assertSame('C:\\new (Kopie)', \WpState::$posts[$id]->post_title);
        $this->assertSame('x\\y', \WpState::$meta[$id]['_yoast_wpseo_title']);
    }

    public function testServerToolListMatchesRegisteredAbilityNames(): void
    {
        $this->assertCount(9, Abilities::NAMES);
        $this->assertSame(array_unique(Abilities::NAMES), Abilities::NAMES);
        $this->assertNotNull(Contract::current());
    }
}
