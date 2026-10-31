<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\ReplaceOriginal;

final class ReplaceOriginalTest extends WpTestCase
{
    private const MODIFIED = '2026-10-01 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        \WpState::$posts[10] = (object) ['ID' => 10, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Leistungen', 'post_content' => 'old', 'post_modified_gmt' => self::MODIFIED];
        \WpState::$posts[11] = (object) ['ID' => 11, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Leistungen (Kopie)', 'post_content' => 'new', 'post_modified_gmt' => ''];
        \WpState::$meta[11] = ['_wpsmcp_copy_of' => 10, '_wpsmcp_copy_modified_gmt' => self::MODIFIED, '_wp_page_template' => 'wide.php'];
        \WpState::$meta[10] = ['_wp_page_template' => 'default.php', '_yoast_wpseo_title' => 'Old SEO'];
        \WpState::$fields[10] = [['acf_fc_layout' => 'hero', 'title' => 'Old'], ['acf_fc_layout' => 'team'], ['acf_fc_layout' => 'note', 'text' => 'see https://old.example/x']];
        \WpState::$fields[11] = [['acf_fc_layout' => 'hero', 'title' => 'New'], ['acf_fc_layout' => 'note', 'text' => 'see https://new.example/y']];
        \WpState::$currentCaps = ['edit_pages', 'edit_post', 'publish_pages'];
    }

    private function nonce(string $modified = self::MODIFIED): string
    {
        $plan = ReplaceOriginal::plan(11);

        return 'nonce-' . ReplaceOriginal::nonceAction(11, $modified, is_array($plan) ? $plan['digest'] : '');
    }

    public function testPlanListsRemovedSectionsAndNewHosts(): void
    {
        $plan = ReplaceOriginal::plan(11);

        $this->assertIsArray($plan);
        $this->assertSame(['team'], $plan['removed']);
        $this->assertSame(['new.example'], $plan['new_hosts']);
        $this->assertFalse($plan['changed']);
    }

    public function testNewHostsIncludeSchemeRelativeAndDisguisedLinks(): void
    {
        \WpState::$fields[11] = [['acf_fc_layout' => 'note', 'text' => '<a href="//cdn.evil.example/x">a</a> <a href="&#x2F;&#x2F;ent.example/y">b</a> <a href="/\\back.example">c</a> <a href="HTTPS:\\\\win.example">d</a> https://new.example/a//path.example/b']];

        $plan = ReplaceOriginal::plan(11);

        $this->assertIsArray($plan);
        $this->assertEqualsCanonicalizing(['cdn.evil.example', 'ent.example', 'back.example', 'win.example', 'new.example'], $plan['new_hosts']);
    }

    public function testDoubleSlashInsideTextOrPathIsNoHost(): void
    {
        \WpState::$fields[11] = [['acf_fc_layout' => 'note', 'text' => 'and//or, see /files//x and a.b//c']];

        $plan = ReplaceOriginal::plan(11);

        $this->assertIsArray($plan);
        $this->assertSame([], $plan['new_hosts']);
    }

    public function testOwnSchemeRelativeLinkIsNoNewHost(): void
    {
        \WpState::$fields[11] = [['acf_fc_layout' => 'note', 'text' => '<a href="//example.test/x">a</a>']];

        $plan = ReplaceOriginal::plan(11);

        $this->assertIsArray($plan);
        $this->assertSame([], $plan['new_hosts']);
    }

    public function testNonceFromBeforeAnEditOfTheCopyIsRefused(): void
    {
        $nonce = $this->nonce();
        \WpState::$fields[11][1]['text'] = 'see https://evil.example/swapped';

        $this->assertSame('nonce', ReplaceOriginal::execute(11, $nonce));
        $this->assertSame('old', \WpState::$posts[10]->post_content);
    }

    public function testNonceFromBeforeATitleChangeOfTheCopyIsRefused(): void
    {
        $nonce = $this->nonce();
        \WpState::$posts[11]->post_title = 'Something else';

        $this->assertSame('nonce', ReplaceOriginal::execute(11, $nonce));
    }

    public function testChangedOriginalIsFlagged(): void
    {
        \WpState::$posts[10]->post_modified_gmt = '2026-10-02 08:00:00';

        $plan = ReplaceOriginal::plan(11);

        $this->assertIsArray($plan);
        $this->assertTrue($plan['changed']);
    }

    public function testWithoutPublishCapabilityNothingChanges(): void
    {
        \WpState::$currentCaps = ['edit_pages', 'edit_post'];

        $this->assertSame('forbidden', ReplaceOriginal::execute(11, $this->nonce()));
        $this->assertSame('old', \WpState::$posts[10]->post_content);
    }

    public function testWrongNonceChangesNothing(): void
    {
        $this->assertSame('nonce', ReplaceOriginal::execute(11, 'bad'));
        $this->assertSame('old', \WpState::$posts[10]->post_content);
    }

    public function testNonceFromBeforeAnEditOfTheOriginalIsRefused(): void
    {
        \WpState::$posts[10]->post_modified_gmt = '2026-10-02 08:00:00';

        $this->assertSame('nonce', ReplaceOriginal::execute(11, $this->nonce(self::MODIFIED)));
        $this->assertSame('old', \WpState::$posts[10]->post_content);
    }

    public function testDeletedOriginalCannotBeReplaced(): void
    {
        unset(\WpState::$posts[10]);

        $this->assertSame('no_original', ReplaceOriginal::execute(11, $this->nonce()));
    }

    public function testReplaceWritesCopyKeepsTitleTrashesCopyAndSnapshotsOriginal(): void
    {
        $result = ReplaceOriginal::execute(11, $this->nonce());

        $this->assertIsArray($result);
        $this->assertSame('new', \WpState::$posts[10]->post_content);
        $this->assertSame('Leistungen', \WpState::$posts[10]->post_title);
        $this->assertSame('New', \WpState::$fields[10][0]['title']);
        $this->assertSame('wide.php', \WpState::$meta[10]['_wp_page_template']);
        $this->assertArrayNotHasKey('_yoast_wpseo_title', \WpState::$meta[10]);
        $this->assertSame('trash', \WpState::$posts[11]->post_status);
        $this->assertSame('1', \WpState::$meta[$result['revision']][ReplaceOriginal::PIN_META]);
    }

    public function testRestoreBringsBackSectionsAndMetaFromTheSnapshot(): void
    {
        $result = ReplaceOriginal::execute(11, $this->nonce());
        $this->assertIsArray($result);

        ReplaceOriginal::restore(10, $result['revision']);

        $this->assertCount(3, \WpState::$fields[10]);
        $this->assertSame('Old', \WpState::$fields[10][0]['title']);
        $this->assertSame('default.php', \WpState::$meta[10]['_wp_page_template']);
        $this->assertSame('Old SEO', \WpState::$meta[10]['_yoast_wpseo_title']);
    }

    public function testRestoreOfAnOrdinaryRevisionTouchesNothing(): void
    {
        ReplaceOriginal::restore(10, 99);

        $this->assertCount(3, \WpState::$fields[10]);
    }

    public function testPinnedRevisionSurvivesPruningUnpinnedDoesNot(): void
    {
        \WpState::$meta[50][ReplaceOriginal::PIN_META] = '1';
        $pinned = (object) ['ID' => 50, 'post_type' => 'revision'];
        $plain = (object) ['ID' => 51, 'post_type' => 'revision'];

        $this->assertFalse(ReplaceOriginal::blockPinnedDelete(null, $pinned));
        $this->assertNull(ReplaceOriginal::blockPinnedDelete(null, $plain));
    }
}
