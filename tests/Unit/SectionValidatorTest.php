<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use WpStarterMcp\SectionValidator;

final class SectionValidatorTest extends WpTestCase
{
    /** @var list<array<int, array<string, mixed>>> */
    private array $acfSeen = [];

    /** @var list<array{input: string, message: string}> */
    private array $acfErrors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->acfSeen = [];
        $this->acfErrors = [];
        \WpState::$posts[50] = (object) ['ID' => 50, 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg'];
        \WpState::$posts[51] = (object) ['ID' => 51, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf'];
        \WpState::$posts[52] = (object) ['ID' => 52, 'post_type' => 'page', 'post_mime_type' => ''];
        \WpState::$posts[53] = (object) ['ID' => 53, 'post_type' => 'attachment', 'post_mime_type' => 'video/mp4', 'file' => '/x/a.mp4'];
        \WpState::$posts[54] = (object) ['ID' => 54, 'post_type' => 'attachment', 'post_mime_type' => 'application/x-php', 'file' => '/x/a.php'];
    }

    private function validator(): SectionValidator
    {
        return new SectionValidator($this->contract(), $this->schema(), function (string $key, array $rows): array {
            $this->acfSeen[] = $rows;

            return $this->acfErrors;
        });
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{errors: list<string>, rows: list<array<string, mixed>>}
     */
    private function hero(array $fields): array
    {
        return $this->validator()->validate([['acf_fc_layout' => 'hero', 'title' => 'Hi'] + $fields]);
    }

    public function testValidSectionPassesAndKeepsOnlyNonEmptyValues(): void
    {
        $result = $this->hero(['badge' => '', 'variant' => 'split', 'show' => true, 'overlay' => '40', 'image' => 50, 'category' => 7]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(
            ['acf_fc_layout' => 'hero', 'title' => 'Hi', 'variant' => 'split', 'image' => 50, 'show' => 1, 'overlay' => 40, 'category' => 7],
            $result['rows'][0]
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidHeroFields(): array
    {
        return [
            'unknown field' => [['nope' => 'x']],
            'field key instead of name' => [['field_title' => 'x']],
            'choice outside list' => [['variant' => 'wide']],
            'number below minimum' => [['overlay' => 5]],
            'number above maximum' => [['overlay' => 500]],
            'number not numeric' => [['overlay' => 'lots']],
            'boolean not boolean' => [['show' => 'yes']],
            'text is an array' => [['badge' => ['a']]],
            'text too long' => [['badge' => str_repeat('a', 5001)]],
            'image not a number' => [['image' => 'logo.png']],
            'image is a page' => [['image' => 52]],
            'image is a pdf' => [['image' => 51]],
            'image missing' => [['image' => 999]],
            'term in other taxonomy' => [['category' => 8]],
            'unsupported field type' => [['date' => '2026-01-01']],
            'link without scheme' => [['cta' => ['url' => 'example.com']]],
            'link javascript' => [['cta' => ['url' => 'javascript:alert(1)']]],
            'link data' => [['cta' => ['url' => 'data:text/html;base64,AAAA']]],
            'link protocol relative' => [['cta' => ['url' => '//evil.example/x']]],
            'link credentials' => [['cta' => ['url' => 'https://user:pw@example.com/']]],
            'link bad target' => [['cta' => ['url' => 'https://example.com', 'target' => '_top']]],
            'link extra key' => [['cta' => ['url' => 'https://example.com', 'onclick' => 'x']]],
            'link with quote and tag' => [['cta' => ['url' => 'https://example.com/?a="><script>alert(1)</script>']]],
            'link with single quote' => [['cta' => ['url' => "https://example.com/?a='x"]]],
            'link with whitespace' => [['cta' => ['url' => "https://example.com/\njavascript:x"]]],
        ];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('invalidHeroFields')]
    public function testInvalidValueIsRefusedAndNothingIsReturned(array $fields): void
    {
        $result = $this->hero($fields);

        $this->assertNotSame([], $result['errors']);
        $this->assertSame([], $result['rows']);
    }

    public function testValidLinksPass(): void
    {
        foreach (['https://example.com/a?b=1', 'http://example.com', '/kontakt', '#preise'] as $url) {
            $this->assertSame([], $this->hero(['cta' => ['url' => $url, 'target' => '_blank']])['errors'], $url);
        }
    }

    public function testRequiredFieldIsEnforced(): void
    {
        $result = $this->validator()->validate([['acf_fc_layout' => 'hero']]);

        $this->assertStringContainsString('title is required', implode(' ', $result['errors']));
    }

    public function testRequiredFieldSentEmptyIsRefused(): void
    {
        $result = $this->validator()->validate([['acf_fc_layout' => 'hero', 'title' => '']]);

        $this->assertStringContainsString('title is required', $result['errors'][0]);
    }

    public function testLayoutOutsideTheAllowlistIsRefused(): void
    {
        $result = $this->validator()->validate([['acf_fc_layout' => 'newsletter', 'action' => 'x']]);

        $this->assertStringContainsString('"newsletter" is not writable', $result['errors'][0]);
    }

    public function testLayoutThatIsAbsentIsRefused(): void
    {
        $this->assertNotSame([], $this->validator()->validate([['title' => 'x']])['errors']);
    }

    public function testMoreThan40SectionsAreRefused(): void
    {
        $sections = array_fill(0, 41, ['acf_fc_layout' => 'hero', 'title' => 'x']);

        $this->assertStringContainsString('At most 40', $this->validator()->validate($sections)['errors'][0]);
        $this->assertSame([], $this->acfSeen);
    }

    public function testMoreThan256KbAreRefused(): void
    {
        $section = ['acf_fc_layout' => 'hero', 'title' => 'x', 'copy' => str_repeat('a', 90000)];

        $this->assertStringContainsString('256 KB', $this->validator()->validate([$section, $section, $section])['errors'][0]);
    }

    public function testObjectInsteadOfListIsRefused(): void
    {
        $this->assertNotSame([], $this->validator()->validate(['a' => ['acf_fc_layout' => 'hero', 'title' => 'x']])['errors']);
    }

    public function testNestedRowsAreValidatedAndUnknownNestedFieldsRefused(): void
    {
        $bad = ['acf_fc_layout' => 'team', 'members' => [['name' => 'A', 'email' => 'not-an-email'], ['name' => 'B', 'icon' => 'rocket'], ['name' => 'C', 'extra' => 1]]];

        $errors = implode("\n", $this->validator()->validate([$bad])['errors']);

        $this->assertStringContainsString('row 1 > email', $errors);
        $this->assertStringContainsString('row 2 > icon', $errors);
        $this->assertStringContainsString('unknown field "extra"', $errors);
    }

    public function testNestedRequiredFieldIsEnforced(): void
    {
        $result = $this->validator()->validate([['acf_fc_layout' => 'team', 'members' => [['email' => 'a@b.de']]]]);

        $this->assertStringContainsString('members row 1 > name is required', $result['errors'][0]);
    }

    public function testNestedModulesAreLimitedToAllowedLayouts(): void
    {
        $section = ['acf_fc_layout' => 'tabs', 'tabs' => [['title' => 'A', 'modules' => [['acf_fc_layout' => 'newsletter', 'action' => 'x']]]]];

        $this->assertStringContainsString('no allowed acf_fc_layout', implode(' ', $this->validator()->validate([$section])['errors']));
    }

    public function testNestedModuleFieldsAreValidated(): void
    {
        $section = ['acf_fc_layout' => 'tabs', 'tabs' => [['modules' => [['acf_fc_layout' => 'note', 'text' => 'ok', 'junk' => 1]]]]];

        $this->assertStringContainsString('unknown field "junk"', implode(' ', $this->validator()->validate([$section])['errors']));
    }

    public function testEmbedMustBeHttpsOnAnAllowedHost(): void
    {
        $embed = fn (string $url): array => $this->validator()->validate([['acf_fc_layout' => 'embed', 'url' => $url]])['errors'];

        $this->assertSame([], $embed('https://player.vimeo.com/video/1'));
        $this->assertNotSame([], $embed('https://evil.example/video/1'));
        $this->assertNotSame([], $embed('http://player.vimeo.com/video/1'));
        $this->assertNotSame([], $embed('javascript:alert(1)//player.vimeo.com'));
    }

    public function testGalleryNeedsImagesOnly(): void
    {
        $gallery = fn (mixed $ids): array => $this->validator()->validate([['acf_fc_layout' => 'embed', 'images' => $ids]])['errors'];

        $this->assertSame([], $gallery([50]));
        $this->assertNotSame([], $gallery([50, 51]));
        $this->assertNotSame([], $gallery('50'));
    }

    public function testFileMustMatchTheAllowedExtensions(): void
    {
        $file = fn (int $id): array => $this->validator()->validate([['acf_fc_layout' => 'embed', 'video' => $id]])['errors'];

        $this->assertSame([], $file(53));
        $this->assertNotSame([], $file(54));
        $this->assertNotSame([], $file(50));
    }

    public function testRichTextDropsFormsEvenThoughTheThemeAllowsThem(): void
    {
        $html = '<p>Hello <strong>you</strong></p><form action="https://evil.example"><input name="pw"></form><script>x()</script>';

        $result = $this->hero(['copy' => $html]);

        $this->assertSame('<p>Hello <strong>you</strong></p>x()', $result['rows'][0]['copy']);
        $allowed = array_keys(\WpState::$ksesCalls[0][1]);
        $this->assertNotContains('form', $allowed);
        $this->assertNotContains('input', $allowed);
        $this->assertNotContains('script', $allowed);
        $this->assertNotContains('iframe', $allowed);
        $this->assertContains('p', $allowed);
    }

    public function testShortcodesInPlainTextAreStripped(): void
    {
        $result = $this->hero(['badge' => 'New [contact-form-7 id="1"] now']);

        $this->assertSame('New  now', $result['rows'][0]['badge']);
    }

    public function testRichTextOnlyKeepsHttpAndHttpsProtocols(): void
    {
        $this->hero(['copy' => '<a href="javascript:x()">x</a>']);

        $this->assertCount(1, \WpState::$ksesCalls);
    }

    public function testShortcodesAreStrippedExceptIcon(): void
    {
        $this->assertSame('a [icon name="star"] b c ', SectionValidator::cleanHtml('a [icon name="star"] b [gallery ids="1"][/gallery]c [contact-form-7 id="9"]'));
    }

    public function testStrippedShortcodeCannotBeRebuiltByNesting(): void
    {
        $this->assertStringNotContainsString('[gallery', SectionValidator::cleanHtml('[gal[gallery]lery ids=1]'));
    }

    public function testKeepRowIsCopiedFromTheStoredPage(): void
    {
        $stored = ['acf_fc_layout' => 'newsletter', 'action' => 'https://x.example'];

        $result = $this->validator()->validate([['acf_fc_layout' => 'newsletter', 'keep' => 'ref-1']], ['ref-1' => $stored]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([$stored], $result['rows']);
    }

    public function testUnknownKeepReferenceIsRefused(): void
    {
        $result = $this->validator()->validate([['acf_fc_layout' => 'newsletter', 'keep' => 'forged']], ['ref-1' => ['acf_fc_layout' => 'newsletter']]);

        $this->assertStringContainsString('keep reference is unknown', $result['errors'][0]);
    }

    public function testKeepReferenceForAnotherLayoutIsRefused(): void
    {
        $result = $this->validator()->validate([['acf_fc_layout' => 'hero', 'keep' => 'ref-1']], ['ref-1' => ['acf_fc_layout' => 'newsletter']]);

        $this->assertNotSame([], $result['errors']);
    }

    public function testKeepSectionCannotSmuggleFieldValues(): void
    {
        $result = $this->validator()->validate([['acf_fc_layout' => 'newsletter', 'keep' => 'ref-1', 'action' => 'https://evil.example']], ['ref-1' => ['acf_fc_layout' => 'newsletter']]);

        $this->assertNotSame([], $result['errors']);
    }

    public function testAcfErrorsAreReportedWithSectionAndFieldName(): void
    {
        $this->acfErrors = [['input' => 'acf[field_page_sections][1][field_members][0][field_member_email]', 'message' => "'x' is not a valid email address"]];

        $result = $this->validator()->validate([
            ['acf_fc_layout' => 'hero', 'title' => 'a'],
            ['acf_fc_layout' => 'team', 'members' => [['name' => 'A', 'email' => 'a@b.de']]],
        ]);

        $this->assertSame(["Section 2 > members row 1 > email: 'x' is not a valid email address"], $result['errors']);
        $this->assertSame([], $result['rows']);
    }

    public function testAcfIsGivenFieldKeysNotNames(): void
    {
        $this->validator()->validate([['acf_fc_layout' => 'team', 'members' => [['name' => 'A', 'email' => 'a@b.de']]]]);

        $this->assertSame([['acf_fc_layout' => 'team', 'field_members' => [['field_name' => 'A', 'field_member_email' => 'a@b.de']]]], $this->acfSeen[0]);
    }

    public function testAcfReceivesNestedModulesByKey(): void
    {
        $this->validator()->validate([['acf_fc_layout' => 'tabs', 'tabs' => [['title' => 'T', 'modules' => [['acf_fc_layout' => 'note', 'text' => 'x']]]]]]);

        $this->assertSame(
            [['acf_fc_layout' => 'tabs', 'field_tabs' => [['field_title' => 'T', 'field_modules' => [['acf_fc_layout' => 'note', 'field_text' => 'x']]]]]],
            $this->acfSeen[0]
        );
    }

    public function testThemeSectionProblemsAreReported(): void
    {
        $contract = $this->validContract();
        $contract['validate_sections'] = static fn (array $sections): array => ['Table content row 2: 3 cells for 2 columns.'];
        \WpState::$contract = $contract;
        \WpStarterMcp\Contract::reset();

        $result = $this->validator()->validate([['acf_fc_layout' => 'hero', 'title' => 'a']]);

        $this->assertSame(['Table content row 2: 3 cells for 2 columns.'], $result['errors']);
    }

    public function testDeepChecksAreSkippedWhenOwnRulesFailed(): void
    {
        $this->validator()->validate([['acf_fc_layout' => 'hero', 'title' => 'a', 'nope' => 1]]);

        $this->assertSame([], $this->acfSeen);
    }
}
