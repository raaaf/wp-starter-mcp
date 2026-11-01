<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * The tools Claude gets. Every ability checks the theme contract, capability and token scope
 * on each call; writes check `edit_post` on the target. Nothing here publishes, deletes or
 * trashes: `update-draft-page` touches drafts only, and live pages are changed through a
 * draft copy that a person publishes.
 */
final class Abilities
{
    public const CATEGORY = 'wpsmcp';
    public const READ = 'mcp:read';
    public const DRAFT = 'mcp:draft';
    public const MAX_PER_PAGE = 20;
    public const WRITES_PER_HOUR = 60;

    /** Ability names, in the order they are offered. Becomes the server's explicit tool list. */
    public const NAMES = [
        'wpsmcp/list-modules',
        'wpsmcp/get-module-schema',
        'wpsmcp/list-pages',
        'wpsmcp/get-page',
        'wpsmcp/get-design-tokens',
        'wpsmcp/search-media',
        'wpsmcp/create-draft-page',
        'wpsmcp/update-draft-page',
        'wpsmcp/create-draft-copy',
    ];

    private const HANDOVER = ' Always give the person the edit_url and preview_url from the result so they can review the draft; nothing is public until they publish it themselves.';

    public static function register(): void
    {
        add_action('wp_abilities_api_categories_init', static function (): void {
            wp_register_ability_category(self::CATEGORY, [
                'label' => 'WP Starter',
                'description' => 'Build draft pages from modules.',
            ]);
        });

        add_action('wp_abilities_api_init', [self::class, 'registerAbilities']);
    }

    public static function registerAbilities(): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $sections = [
            'type' => 'array',
            'description' => 'Sections in page order. Each is an object with acf_fc_layout (module name) and that module\'s fields from get-module-schema, or {"acf_fc_layout": "...", "keep": "<reference>"} to keep a section exactly as get-page returned it.',
            'items' => ['type' => 'object'],
        ];

        $definitions = [
            'list-modules' => [
                'description' => 'List the modules (section types) a page can be built from, with their labels. Call get-module-schema for the fields of one module.',
                'scope' => self::READ,
                'input' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'callback' => [self::class, 'listModules'],
            ],
            'get-module-schema' => [
                'description' => 'Get the fields of one module: names, types, choices, limits and which are required.',
                'scope' => self::READ,
                'input' => ['type' => 'object', 'properties' => ['module' => ['type' => 'string']], 'required' => ['module'], 'additionalProperties' => false],
                'callback' => [self::class, 'getModuleSchema'],
            ],
            'list-pages' => [
                'description' => 'List pages you may edit, newest change first. At most 20 per call; use page for more.',
                'scope' => self::READ,
                'input' => ['type' => 'object', 'properties' => ['search' => ['type' => 'string'], 'page' => $id, 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE]], 'additionalProperties' => false],
                'callback' => [self::class, 'listPages'],
            ],
            'get-page' => [
                'description' => 'Read a page: title, status and its sections in the same format create-draft-page and update-draft-page accept. Sections that cannot be edited are returned as keep references.',
                'scope' => self::READ,
                'input' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id'], 'additionalProperties' => false],
                'callback' => [self::class, 'getPage'],
                'target' => 'read',
            ],
            'get-design-tokens' => [
                'description' => 'Get the design tokens (base colours and token file locations) of this site, so content matches its look.',
                'scope' => self::READ,
                'input' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'callback' => [self::class, 'getDesignTokens'],
            ],
            'search-media' => [
                'description' => 'Search images in the media library. Returns IDs to use in image fields. Images cannot be uploaded.',
                'scope' => self::READ,
                'input' => ['type' => 'object', 'properties' => ['search' => ['type' => 'string'], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE]], 'additionalProperties' => false],
                'callback' => [self::class, 'searchMedia'],
                'cap' => 'upload_files',
            ],
            'create-draft-page' => [
                'description' => 'Create a new draft page from modules. It is never published by this tool.' . self::HANDOVER,
                'scope' => self::DRAFT,
                'input' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'sections' => $sections], 'required' => ['title', 'sections'], 'additionalProperties' => false],
                'callback' => [self::class, 'createDraftPage'],
                'write' => true,
            ],
            'update-draft-page' => [
                'description' => 'Replace ALL sections of an existing DRAFT page (read it with get-page first and send every section back; an empty list is refused). The previous state is kept as a revision. Live pages are refused: use create-draft-copy for those.' . self::HANDOVER,
                'scope' => self::DRAFT,
                'input' => ['type' => 'object', 'properties' => ['id' => $id, 'sections' => $sections, 'title' => ['type' => 'string']], 'required' => ['id', 'sections'], 'additionalProperties' => false],
                'callback' => [self::class, 'updateDraftPage'],
                'target' => 'edit',
                'write' => true,
            ],
            'create-draft-copy' => [
                'description' => 'Copy a page (live or draft) into a new draft, so it can be changed safely. The original stays untouched; a person publishes or replaces it.' . self::HANDOVER,
                'scope' => self::DRAFT,
                'input' => ['type' => 'object', 'properties' => ['id' => $id, 'title' => ['type' => 'string']], 'required' => ['id'], 'additionalProperties' => false],
                'callback' => [self::class, 'createDraftCopy'],
                'target' => 'edit',
                'write' => true,
            ],
        ];

        foreach ($definitions as $name => $definition) {
            $write = !empty($definition['write']);

            wp_register_ability(self::CATEGORY . '/' . $name, [
                'label' => ucfirst(str_replace('-', ' ', $name)),
                'description' => $definition['description'],
                'category' => self::CATEGORY,
                'input_schema' => $definition['input'],
                'execute_callback' => static fn (mixed $input = null): mixed => $definition['callback'](is_array($input) ? $input : []),
                'permission_callback' => static fn (mixed $input = null): bool|\WP_Error => self::permit(
                    $definition['scope'],
                    is_array($input) ? $input : [],
                    $definition['target'] ?? null,
                    $definition['cap'] ?? 'edit_pages'
                ),
                'meta' => ['annotations' => ['readonly' => !$write, 'destructive' => $name === 'update-draft-page', 'idempotent' => $name === 'update-draft-page']],
            ]);
        }
    }

    /**
     * Contract, capability, scope and, for a target page, edit_post on it.
     *
     * @param array<string, mixed> $input
     */
    public static function permit(string $scope, array $input, ?string $target, string $capability = 'edit_pages'): bool|\WP_Error
    {
        $contract = Contract::current();
        if ($contract === null) {
            return new \WP_Error('wpsmcp_unavailable', Contract::message());
        }

        if (!current_user_can($capability)) {
            return new \WP_Error('wpsmcp_forbidden', 'Your account may not do this.');
        }

        if (!BearerGate::allowsScope($scope)) {
            return new \WP_Error('wpsmcp_scope', 'This connection was not granted the permission for this action.');
        }

        if ($target !== null) {
            $id = isset($input['id']) && is_int($input['id']) ? $input['id'] : 0;

            if ($id < 1 || !$contract->isReadable($id) || !current_user_can('edit_post', $id)) {
                return new \WP_Error('wpsmcp_page', 'This page does not exist or you may not edit it.');
            }
        }

        return true;
    }

    private static function contract(): Contract
    {
        $contract = Contract::current();
        if ($contract === null) {
            throw new \LogicException('Contract unavailable.');
        }

        return $contract;
    }

    private static function schema(): ModuleSchema
    {
        return new ModuleSchema(self::contract());
    }

    private static function content(): PageContent
    {
        return new PageContent(self::contract(), self::schema());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function listModules(array $input): array
    {
        return ['modules' => self::schema()->summary()];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public static function getModuleSchema(array $input): array|\WP_Error
    {
        $module = is_string($input['module'] ?? null) ? $input['module'] : '';
        $schema = self::schema()->compact($module);

        return $schema ?? new \WP_Error('wpsmcp_module', 'Unknown or read-only module. Use list-modules for the names.');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function listPages(array $input): array
    {
        $contract = self::contract();
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? self::MAX_PER_PAGE)));

        $query = new \WP_Query([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending'],
            'posts_per_page' => $perPage,
            'paged' => max(1, (int) ($input['page'] ?? 1)),
            'orderby' => 'modified',
            'order' => 'DESC',
            's' => is_string($input['search'] ?? null) ? $input['search'] : '',
            'has_password' => false,
            'fields' => 'ids',
            'no_found_rows' => false,
        ]);

        $pages = [];
        foreach ($query->get_posts() as $found) {
            $id = $found instanceof \WP_Post ? $found->ID : (int) $found;
            if ($contract->isReadable($id) && current_user_can('edit_post', $id)) {
                $pages[] = self::summarise($id);
            }
        }

        return ['pages' => $pages, 'page' => max(1, (int) ($input['page'] ?? 1)), 'total_pages' => (int) $query->max_num_pages];
    }

    /**
     * WordPress leaves post_modified_gmt zero on a draft until its first update.
     *
     * @param object{post_modified: string, post_modified_gmt: string} $post
     */
    public static function modifiedGmt(object $post): string
    {
        return $post->post_modified_gmt === '0000-00-00 00:00:00'
            ? get_gmt_from_date($post->post_modified)
            : $post->post_modified_gmt;
    }

    /** @return array<string, mixed> */
    private static function summarise(int $id): array
    {
        $post = get_post($id);

        return [
            'id' => $id,
            'title' => $post ? get_the_title($post) : '',
            'status' => $post ? $post->post_status : '',
            'modified' => $post ? self::modifiedGmt($post) : '',
            'url' => get_permalink($id),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function getPage(array $input): array
    {
        $id = (int) $input['id'];
        $post = get_post($id);

        return self::summarise($id) + [
            'slug' => $post ? $post->post_name : '',
            'sections' => self::content()->forClaude($id),
            'can_update_directly' => $post !== null && $post->post_status === 'draft',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function getDesignTokens(array $input): array
    {
        return self::contract()->designTokens();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function searchMedia(array $input): array
    {
        $posts = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 10))),
            's' => is_string($input['search'] ?? null) ? $input['search'] : '',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $images = [];
        foreach ($posts as $post) {
            $meta = wp_get_attachment_metadata($post->ID);
            $images[] = [
                'id' => $post->ID,
                'title' => get_the_title($post),
                'alt' => (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true),
                'width' => is_array($meta) ? (int) ($meta['width'] ?? 0) : 0,
                'height' => is_array($meta) ? (int) ($meta['height'] ?? 0) : 0,
                'url' => wp_get_attachment_image_url($post->ID, 'medium'),
            ];
        }

        return ['images' => $images];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public static function createDraftPage(array $input): array|\WP_Error
    {
        $title = sanitize_text_field(is_string($input['title'] ?? null) ? $input['title'] : '');
        if ($title === '') {
            return new \WP_Error('wpsmcp_title', 'A title is required.');
        }

        $checked = self::check($input, []);
        if (is_wp_error($checked)) {
            return $checked;
        }

        $id = wp_insert_post(wp_slash(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => mb_substr($title, 0, 200)]), true);
        if (is_wp_error($id)) {
            return $id;
        }

        self::write($id, $checked);
        Audit::record('create_draft_page', get_current_user_id(), $id, BearerGate::clientHost());

        return self::handover($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public static function updateDraftPage(array $input): array|\WP_Error
    {
        $id = (int) $input['id'];
        $post = get_post($id);

        if (!$post || $post->post_status !== 'draft') {
            return new \WP_Error('wpsmcp_not_draft', 'Only drafts can be updated. Use create-draft-copy to work on a copy of a live page.');
        }

        // Everything is replaced: an empty list would wipe the page, so it is never meant.
        if (!is_array($input['sections'] ?? null) || $input['sections'] === []) {
            return new \WP_Error('wpsmcp_empty_sections', 'sections is empty, which would delete every section of the draft. Read the page with get-page and send all sections back, with your changes.');
        }

        $checked = self::check($input, self::content()->keepable($id));
        if (is_wp_error($checked)) {
            return $checked;
        }

        if (ReplaceOriginal::snapshot($id, self::content(), [], false) === 0) {
            return new \WP_Error('wpsmcp_snapshot', 'The previous state of the draft could not be saved, so nothing was changed.');
        }

        self::write($id, $checked);

        if (is_string($input['title'] ?? null) && sanitize_text_field($input['title']) !== '') {
            wp_update_post(wp_slash(['ID' => $id, 'post_title' => mb_substr(sanitize_text_field($input['title']), 0, 200)]));
        }

        Audit::record('update_draft_page', get_current_user_id(), $id, BearerGate::clientHost());

        return self::handover($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public static function createDraftCopy(array $input): array|\WP_Error
    {
        $contract = self::contract();
        $sourceId = (int) $input['id'];
        $source = get_post($sourceId);

        if (!$source) {
            return new \WP_Error('wpsmcp_page', 'This page does not exist.');
        }

        if (!self::withinQuota()) {
            return self::quotaError();
        }

        $title = is_string($input['title'] ?? null) && sanitize_text_field($input['title']) !== ''
            ? sanitize_text_field($input['title'])
            : sprintf('%s (Kopie)', $source->post_title);

        // WordPress unslashes these on save.
        $id = wp_insert_post(wp_slash([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => mb_substr($title, 0, 200),
            'post_content' => $source->post_content,
        ]), true);
        if (is_wp_error($id)) {
            return $id;
        }

        // Sections are copied verbatim, rows Claude cannot write included.
        update_field($contract->pageFieldKey(), self::content()->stored($sourceId), $id);

        foreach (array_merge($contract->copyMeta(), ['_wp_page_template', '_thumbnail_id']) as $metaKey) {
            $value = get_post_meta($sourceId, $metaKey, true);
            if ($value !== '') {
                update_post_meta($id, $metaKey, wp_slash($value));
            }
        }

        // Later used to warn when the original changed before it is replaced.
        update_post_meta($id, '_wpsmcp_copy_of', $sourceId);
        update_post_meta($id, '_wpsmcp_copy_modified_gmt', $source->post_modified_gmt);

        $contract->afterWrite($id);
        Audit::record('create_draft_copy', get_current_user_id(), $id, BearerGate::clientHost());

        return self::handover($id) + ['copied_from' => $sourceId];
    }

    private static function withinQuota(): bool
    {
        return (new RateLimiter())->allow('write:' . BearerGate::connectionKey(get_current_user_id()), self::WRITES_PER_HOUR, 3600);
    }

    private static function quotaError(): \WP_Error
    {
        return new \WP_Error('wpsmcp_quota', sprintf('Write limit of %d per hour reached for this connection. Try again later.', self::WRITES_PER_HOUR));
    }

    /**
     * Quota, then validation. Returns the rows to store, or the refusal with every problem.
     *
     * @param array<string, mixed> $input
     * @param array<string, array<string, mixed>> $keepable
     * @return list<array<string, mixed>>|\WP_Error
     */
    private static function check(array $input, array $keepable): array|\WP_Error
    {
        if (!self::withinQuota()) {
            return self::quotaError();
        }

        $sections = is_array($input['sections'] ?? null) ? $input['sections'] : [];
        $result = (new SectionValidator(self::contract(), self::schema()))->validate($sections, $keepable);

        if ($result['errors'] !== []) {
            return new \WP_Error('wpsmcp_invalid', "The sections were not saved:\n- " . implode("\n- ", array_slice($result['errors'], 0, 25)));
        }

        return $result['rows'];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function write(int $id, array $rows): void
    {
        $contract = self::contract();

        update_field($contract->pageFieldKey(), $rows, $id);
        $contract->afterWrite($id);
    }

    /** @return array<string, mixed> */
    private static function handover(int $id): array
    {
        return [
            'id' => $id,
            'status' => 'draft',
            'edit_url' => get_edit_post_link($id, 'raw'),
            'preview_url' => get_preview_post_link($id),
            'note' => 'Give the person edit_url and preview_url. The page is a draft until they publish it.',
        ];
    }
}
