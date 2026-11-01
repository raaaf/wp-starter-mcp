<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpStarterMcp\OAuth\TokenStore;

abstract class WpTestCase extends TestCase
{
    protected int $now = 1_800_000_000;

    protected function setUp(): void
    {
        \WpState::reset();
        // The plugin is installed: its tables exist.
        \WpState::$options[\WpStarterMcp\Schema::OPTION] = \WpStarterMcp\Schema::VERSION;
        ini_set('error_log', '/dev/null');
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $this->now = 1_800_000_000;
        \WpStarterMcp\Contract::reset();
        \WpStarterMcp\BearerGate::forgetToken();
        \WpState::$contract = $this->validContract();
    }

    /** @return array<string, mixed> a version 1 contract with a small set of layouts */
    protected function validContract(): array
    {
        $icons = ['' => 'none', 'star' => 'Star', 'heart' => 'Heart'];
        $text = static fn (string $name, bool $required = false): array => ['key' => 'field_' . $name, 'name' => $name, 'type' => 'text', 'label' => ucfirst($name), 'required' => $required ? 1 : 0];

        $layouts = [
            ['key' => 'layout_hero', 'name' => 'hero', 'label' => 'Hero', 'sub_fields' => [
                ['key' => 'field_tab', 'label' => 'Content', 'type' => 'tab'],
                $text('title', true),
                $text('badge'),
                ['key' => 'field_copy', 'name' => 'copy', 'type' => 'wysiwyg', 'label' => 'Copy'],
                ['key' => 'field_variant', 'name' => 'variant', 'type' => 'button_group', 'label' => 'Variant', 'choices' => ['centered' => 'C', 'split' => 'S']],
                ['key' => 'field_image', 'name' => 'image', 'type' => 'image', 'label' => 'Image'],
                ['key' => 'field_cta', 'name' => 'cta', 'type' => 'link', 'label' => 'CTA'],
                ['key' => 'field_show', 'name' => 'show', 'type' => 'true_false', 'label' => 'Show'],
                ['key' => 'field_overlay', 'name' => 'overlay', 'type' => 'range', 'label' => 'Overlay', 'min' => 20, 'max' => 100],
                ['key' => 'field_cat', 'name' => 'category', 'type' => 'taxonomy', 'taxonomy' => 'category', 'field_type' => 'select', 'label' => 'Category'],
                ['key' => 'field_date', 'name' => 'date', 'type' => 'date_picker', 'label' => 'Date'],
            ]],
            ['key' => 'layout_team', 'name' => 'team', 'label' => 'Team', 'sub_fields' => [
                ['key' => 'field_members', 'name' => 'members', 'type' => 'repeater', 'label' => 'Members', 'sub_fields' => [
                    $text('name', true),
                    ['key' => 'field_member_email', 'name' => 'email', 'type' => 'email', 'label' => 'Email'],
                    ['key' => 'field_member_icon', 'name' => 'icon', 'type' => 'radio', 'label' => 'Icon', 'choices' => $icons],
                ]],
            ]],
            ['key' => 'layout_embed', 'name' => 'embed', 'label' => 'Embed', 'sub_fields' => [
                ['key' => 'field_embed_url', 'name' => 'url', 'type' => 'url', 'label' => 'URL'],
                ['key' => 'field_gallery', 'name' => 'images', 'type' => 'gallery', 'label' => 'Images', 'min' => 1],
                ['key' => 'field_file', 'name' => 'video', 'type' => 'file', 'label' => 'Video', 'mime_types' => 'mp4,webm'],
            ]],
            ['key' => 'layout_tabs', 'name' => 'tabs', 'label' => 'Tabs', 'sub_fields' => [
                ['key' => 'field_tabs', 'name' => 'tabs', 'type' => 'repeater', 'label' => 'Tabs', 'sub_fields' => [
                    $text('title'),
                    ['key' => 'field_modules', 'name' => 'modules', 'type' => 'flexible_content', 'label' => 'Modules', 'layouts' => [
                        ['key' => 'layout_note', 'name' => 'note', 'label' => 'Note', 'sub_fields' => [$text('text')]],
                        ['key' => 'layout_newsletter', 'name' => 'newsletter', 'label' => 'Newsletter', 'sub_fields' => [$text('action')]],
                    ]],
                ]],
            ]],
            ['key' => 'layout_note', 'name' => 'note', 'label' => 'Note', 'sub_fields' => [$text('text')]],
            ['key' => 'layout_newsletter', 'name' => 'newsletter', 'label' => 'Newsletter', 'sub_fields' => [$text('action')]],
        ];

        return [
            'version' => 1,
            'page_field' => 'page_sections',
            'page_field_key' => 'field_page_sections',
            'writable' => ['hero', 'team', 'embed', 'tabs', 'note'],
            'copy_meta' => ['_yoast_wpseo_title'],
            'layouts' => static fn (): array => $layouts,
            'is_readable' => static fn (int $id): bool => $id === 10 || $id === 11,
            'after_write' => static function (int $id): void {
            },
            'is_allowed_embed_host' => static fn (string $url): bool => str_contains($url, 'player.vimeo.com'),
            'design_tokens' => static fn (): array => ['colors' => ['primary' => '#123456']],
            'validate_sections' => static fn (array $sections): array => [],
        ];
    }

    protected function contract(): \WpStarterMcp\Contract
    {
        $contract = \WpStarterMcp\Contract::current();
        $this->assertNotNull($contract);

        return $contract;
    }

    protected function schema(): \WpStarterMcp\ModuleSchema
    {
        return new \WpStarterMcp\ModuleSchema($this->contract());
    }

    protected function store(): TokenStore
    {
        return new TokenStore(fn (): int => $this->now);
    }

    protected function allowUser(int $id): void
    {
        \WpState::$canEdit[$id] = true;
        \WpState::$users[$id] = (object) ['ID' => $id, 'user_email' => "u{$id}@example.test", 'display_name' => "User {$id}", 'user_pass' => 'hash'];
    }

    /** @return array<string, string> */
    protected function authParams(string $challenge): array
    {
        return [
            'response_type' => 'code',
            'client_id' => 'https://claude.ai/oauth/mcp-oauth-client-metadata',
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => \WpStarterMcp\Config::mcpUrl(),
            'scope' => 'mcp:read mcp:draft',
            'state' => 'xyz',
        ];
    }

    protected function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
