<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\PageContent;
use WpStarterMcp\SectionValidator;

final class PageContentTest extends WpTestCase
{
    private function content(): PageContent
    {
        return new PageContent($this->contract(), $this->schema());
    }

    /** @param list<array<string, mixed>> $rows */
    private function storeRows(array $rows): void
    {
        \WpState::$fields[10] = $rows;
    }

    public function testWritableRowIsReturnedWithoutEmptyValues(): void
    {
        $this->storeRows([['acf_fc_layout' => 'hero', 'title' => 'Hi', 'badge' => '', 'variant' => 'split', 'show' => '1', 'overlay' => '40', 'image' => '50', 'cta' => ['url' => 'https://example.com', 'title' => 'Go', 'target' => ''], 'copy' => '', 'category' => '']]);

        $this->assertSame(
            [['acf_fc_layout' => 'hero', 'title' => 'Hi', 'variant' => 'split', 'image' => 50, 'cta' => ['url' => 'https://example.com', 'title' => 'Go', 'target' => ''], 'show' => true, 'overlay' => 40]],
            $this->content()->forClaude(10)
        );
    }

    public function testRowsKeyedByFieldKeyAreReadLikeAcfReturnsThem(): void
    {
        $this->storeRows([['acf_fc_layout' => 'team', 'field_members' => [['field_name' => 'A', 'field_member_email' => 'a@b.de', 'field_member_icon' => 'star']]], ['acf_fc_layout' => 'hero', 'field_title' => 'Hi']]);

        $this->assertSame(
            [['acf_fc_layout' => 'team', 'members' => [['name' => 'A', 'email' => 'a@b.de', 'icon' => 'star']]], ['acf_fc_layout' => 'hero', 'title' => 'Hi']],
            $this->content()->forClaude(10)
        );
    }

    public function testFalseBooleanAndEmptyLinkAreOmitted(): void
    {
        $this->storeRows([['acf_fc_layout' => 'hero', 'title' => 'Hi', 'show' => '0', 'cta' => ['url' => '', 'title' => '', 'target' => '']]]);

        $this->assertSame([['acf_fc_layout' => 'hero', 'title' => 'Hi']], $this->content()->forClaude(10));
    }

    public function testRowOfALayoutClaudeCannotWriteBecomesAKeepReference(): void
    {
        $row = ['acf_fc_layout' => 'newsletter', 'action' => 'https://x.example'];
        $this->storeRows([['acf_fc_layout' => 'hero', 'title' => 'Hi'], $row]);

        $sections = $this->content()->forClaude(10);

        $this->assertSame(['acf_fc_layout', 'keep'], array_keys($sections[1]));
        $this->assertSame($row, $this->content()->keepable(10)[$sections[1]['keep']]);
    }

    public function testRowWithAFieldUnknownToTheSchemaIsKeptNotDropped(): void
    {
        $this->storeRows([['acf_fc_layout' => 'hero', 'title' => 'Hi', 'fork_field' => 'precious']]);

        $this->assertArrayHasKey('keep', $this->content()->forClaude(10)[0]);
    }

    public function testRowHoldingAnUnsupportedFieldValueIsKept(): void
    {
        $this->storeRows([['acf_fc_layout' => 'hero', 'title' => 'Hi', 'date' => '20260101']]);

        $this->assertArrayHasKey('keep', $this->content()->forClaude(10)[0]);
    }

    public function testNestedModuleOutsideTheAllowlistKeepsTheWholeSection(): void
    {
        $this->storeRows([['acf_fc_layout' => 'tabs', 'tabs' => [['title' => 'A', 'modules' => [['acf_fc_layout' => 'newsletter', 'action' => 'x']]]]]]);

        $this->assertArrayHasKey('keep', $this->content()->forClaude(10)[0]);
    }

    public function testKeepReferenceStopsResolvingWhenTheRowChanges(): void
    {
        $this->storeRows([['acf_fc_layout' => 'newsletter', 'action' => 'a']]);
        $ref = $this->content()->forClaude(10)[0]['keep'];

        $this->storeRows([['acf_fc_layout' => 'newsletter', 'action' => 'b']]);

        $this->assertArrayNotHasKey($ref, $this->content()->keepable(10));
    }

    public function testKeepReferenceStopsResolvingWhenTheRowMoves(): void
    {
        $this->storeRows([['acf_fc_layout' => 'newsletter', 'action' => 'a']]);
        $ref = $this->content()->forClaude(10)[0]['keep'];

        $this->storeRows([['acf_fc_layout' => 'hero', 'title' => 'x'], ['acf_fc_layout' => 'newsletter', 'action' => 'a']]);

        $this->assertArrayNotHasKey($ref, $this->content()->keepable(10));
    }

    public function testReadThenWriteLeavesTheOutputUnchanged(): void
    {
        $this->storeRows([
            ['acf_fc_layout' => 'hero', 'title' => 'Hi', 'variant' => 'split', 'show' => '1', 'image' => '50', 'overlay' => '40'],
            ['acf_fc_layout' => 'newsletter', 'action' => 'https://x.example'],
            ['acf_fc_layout' => 'team', 'members' => [['name' => 'A', 'email' => 'a@b.de', 'icon' => 'star'], ['name' => 'B', 'email' => '', 'icon' => '']]],
            ['acf_fc_layout' => 'tabs', 'tabs' => [['title' => 'T', 'modules' => [['acf_fc_layout' => 'note', 'text' => 'x']]]]],
        ]);
        \WpState::$posts[50] = (object) ['ID' => 50, 'post_type' => 'attachment', 'post_mime_type' => 'image/png'];
        $before = $this->content()->forClaude(10);

        $validator = new SectionValidator($this->contract(), $this->schema(), static fn (): array => []);
        $result = $validator->validate($before, $this->content()->keepable(10));
        $this->assertSame([], $result['errors']);

        // Write the result back as ACF would store it (strings), then read again.
        \WpState::$fields[10] = json_decode((string) json_encode($result['rows']), true);

        $after = (new PageContent($this->contract(), $this->schema()))->forClaude(10);
        $this->assertSame($before, $after);
        $this->assertSame('newsletter', $result['rows'][1]['acf_fc_layout']);
        $this->assertSame('https://x.example', $result['rows'][1]['action']);
    }
}
