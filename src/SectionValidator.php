<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Default-deny validation and sanitising of the sections Claude wants to write.
 *
 * Own rules first (allowlisted layouts, unknown fields refused, per-type checks, rich text
 * through an explicit kses, http/https links, image attachments, embed hosts, size caps), then
 * ACF's field validation with the theme's validators, then the contract's validate_sections.
 * Sections referencing existing content as `keep` are copied from the page, never validated.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class SectionValidator
{
    public const MAX_SECTIONS = 40;
    public const MAX_BYTES = 262144;
    private const MAX_TEXT = 5000;
    private const MAX_HTML = 100000;
    private const MAX_ROWS = 200;
    private const MAX_IMAGES = 60;
    private const MAX_URL = 2048;

    /** Tags that make a form or run code, removed from the allowlist even for unfiltered_html. */
    private const FORBIDDEN_TAGS = [
        'form', 'input', 'select', 'option', 'optgroup', 'textarea', 'button', 'fieldset', 'legend',
        'label', 'datalist', 'output', 'script', 'style', 'iframe', 'object', 'embed', 'applet', 'meta', 'link', 'base',
    ];

    /** Fields whose URL is loaded as an embed: layout => field names. Host must pass the theme's allowlist. */
    private const EMBED_FIELDS = [
        'embed' => ['url'],
        'map' => ['embed_url'],
        'video' => ['video_url'],
    ];

    /** @var list<string> */
    private array $errors = [];

    /** @var \Closure(string, array<int, array<string, mixed>>): list<array{input: string, message: string}> */
    private readonly \Closure $acf;

    /** @param (\Closure(string, array<int, array<string, mixed>>): list<array{input: string, message: string}>)|null $acf */
    public function __construct(
        private readonly Contract $contract,
        private readonly ModuleSchema $schema,
        ?\Closure $acf = null,
    ) {
        $this->acf = $acf ?? static fn (string $key, array $rows): array => AcfCheck::run($key, $rows);
    }

    /**
     * @param array<mixed> $sections
     * @param array<string, array<string, mixed>> $keepable stored rows of the page by keep reference
     * @return array{errors: list<string>, rows: list<array<string, mixed>>}
     */
    public function validate(array $sections, array $keepable = []): array
    {
        $this->errors = [];

        if (!array_is_list($sections)) {
            return $this->fail('Sections must be a list.');
        }
        if (count($sections) > self::MAX_SECTIONS) {
            return $this->fail(sprintf('At most %d sections are allowed.', self::MAX_SECTIONS));
        }
        if (strlen((string) wp_json_encode($sections)) > self::MAX_BYTES) {
            return $this->fail(sprintf('The sections are larger than %d KB.', self::MAX_BYTES / 1024));
        }

        $rows = [];
        $own = [];

        foreach ($sections as $index => $section) {
            $label = 'Section ' . ($index + 1);

            if (!is_array($section)) {
                $this->errors[] = $label . ' must be an object.';
                continue;
            }

            if (array_key_exists('keep', $section)) {
                $kept = $this->keep($section, $keepable, $label);
                if ($kept !== null) {
                    $rows[] = $kept;
                }
                continue;
            }

            $name = $section['acf_fc_layout'] ?? null;
            $layout = is_string($name) ? $this->schema->find($name) : null;
            if ($layout === null) {
                $this->errors[] = sprintf('%s: layout "%s" is not writable.', $label, is_string($name) ? $name : '');
                continue;
            }

            $clean = $this->row($layout, $section, $label . ' (' . $name . ')');
            $rows[] = $clean;
            $own[$index] = $clean;
        }

        if ($this->errors === []) {
            $this->errors = array_merge($this->errors, $this->deepChecks($own));
        }

        return ['errors' => $this->errors, 'rows' => $this->errors === [] ? $rows : []];
    }

    /** @return array{errors: list<string>, rows: list<array<string, mixed>>} */
    private function fail(string $message): array
    {
        return ['errors' => [$message], 'rows' => []];
    }

    /**
     * @param array<mixed> $section
     * @param array<string, array<string, mixed>> $keepable
     * @return array<string, mixed>|null
     */
    private function keep(array $section, array $keepable, string $label): ?array
    {
        $ref = $section['keep'];

        if (array_diff(array_keys($section), ['acf_fc_layout', 'keep']) !== [] || !is_string($ref)) {
            $this->errors[] = $label . ': a keep section holds only acf_fc_layout and keep.';

            return null;
        }

        $stored = $keepable[$ref] ?? null;
        if ($stored === null || ($stored['acf_fc_layout'] ?? null) !== ($section['acf_fc_layout'] ?? null)) {
            $this->errors[] = $label . ': the keep reference is unknown or the page changed since it was read. Read the page again.';

            return null;
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $layout, array $row, string $path): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = $layout['fields'];
        $byName = [];
        foreach ($fields as $field) {
            $byName[(string) $field['name']] = $field;
        }

        foreach (array_keys($row) as $key) {
            if ($key !== 'acf_fc_layout' && !isset($byName[$key])) {
                $this->errors[] = sprintf('%s: unknown field "%s".', $path, (string) $key);
            }
        }

        $clean = ['acf_fc_layout' => $layout['name']];

        foreach ($byName as $name => $field) {
            $value = array_key_exists($name, $row) ? $this->value($field, $row[$name], $path . ' > ' . $name, (string) $layout['name']) : null;

            if ($value === null) {
                if ($field['required'] && !$field['conditional'] && $field['kind'] !== 'unsupported' && array_key_exists($name, $row) === false) {
                    $this->errors[] = sprintf('%s > %s is required.', $path, $name);
                }
                continue;
            }

            $clean[$name] = $value;
        }

        return $clean;
    }

    /**
     * A cleaned value, or null when empty (empty values are not stored).
     *
     * @param array<string, mixed> $field
     */
    private function value(array $field, mixed $value, string $path, string $layoutName): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            if ($field['required'] && !$field['conditional']) {
                $this->errors[] = sprintf('%s is required.', $path);
            }

            return null;
        }

        return match ($field['kind']) {
            'text' => $this->text($value, $path),
            'html' => $this->html($value, $path),
            'email' => $this->email($value, $path),
            'url' => $this->url($value, $path, in_array((string) $field['name'], self::EMBED_FIELDS[$layoutName] ?? [], true)),
            'link' => $this->link($value, $path),
            'image' => $this->image($value, $path),
            'images' => $this->images($value, $path, (int) ($field['min'] ?? 0)),
            'file' => $this->file($value, $path, $field),
            'choice' => $this->choice($value, $path, $field),
            'boolean' => $this->boolean($value, $path),
            'number' => $this->number($value, $path, $field),
            'term' => $this->term($value, $path, $field),
            'rows' => $this->rows($value, $path, $field, $layoutName),
            'modules' => $this->modules($value, $path, $field),
            default => $this->reject(sprintf('%s cannot be written.', $path)),
        };
    }

    private function reject(string $message): null
    {
        $this->errors[] = $message;

        return null;
    }

    private function text(mixed $value, string $path): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return $this->reject($path . ' must be text.');
        }

        $text = self::stripShortcodes(sanitize_textarea_field((string) $value));
        if (mb_strlen($text) > self::MAX_TEXT) {
            return $this->reject(sprintf('%s is longer than %d characters.', $path, self::MAX_TEXT));
        }

        return $text === '' ? null : $text;
    }

    private function html(mixed $value, string $path): ?string
    {
        if (!is_string($value)) {
            return $this->reject($path . ' must be HTML text.');
        }
        if (strlen($value) > self::MAX_HTML) {
            return $this->reject(sprintf('%s is longer than %d characters.', $path, self::MAX_HTML));
        }

        $clean = self::cleanHtml($value);

        return trim($clean) === '' ? null : $clean;
    }

    /**
     * Explicit kses with the post allowlist minus form and code tags, for every user. The
     * theme's allowFormControlTags would keep forms, and unfiltered_html users skip core kses.
     * Shortcodes are stripped except [icon]; links keep http and https only.
     */
    public static function cleanHtml(string $html): string
    {
        $allowed = array_diff_key(wp_kses_allowed_html('post'), array_flip(self::FORBIDDEN_TAGS));

        $html = self::stripShortcodes($html);
        $html = wp_kses($html, $allowed, ['http', 'https']);

        return self::stripShortcodes($html);
    }

    public static function stripShortcodes(string $text): string
    {
        return (string) preg_replace('/\[\/?(?!icon\b)[A-Za-z_][\w-]*(?:\s[^\]]*)?\]/', '', $text);
    }

    private function email(mixed $value, string $path): ?string
    {
        if (!is_string($value) || is_email(trim($value)) === false) {
            return $this->reject($path . ' must be an email address.');
        }

        return trim($value);
    }

    private function url(mixed $value, string $path, bool $embed): ?string
    {
        if (!is_string($value) || !self::isHttpUrl(trim($value))) {
            return $this->reject($path . ' must be an http or https address.');
        }

        $url = trim($value);

        if ($embed && (!str_starts_with(strtolower($url), 'https://') || !$this->contract->isAllowedEmbedHost($url))) {
            return $this->reject($path . ' must be an https address of an allowed embed provider.');
        }

        return $url;
    }

    /** @return array<string, string>|null */
    private function link(mixed $value, string $path): ?array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['url', 'title', 'target']) !== []) {
            return $this->reject($path . ' must be an object with url, title and target.');
        }

        $url = $value['url'] ?? '';
        if (!is_string($url) || (!self::isHttpUrl(trim($url)) && !self::isLocalLink(trim($url)))) {
            return $this->reject($path . ' > url must be an http or https address, a path starting with / or an anchor.');
        }

        $target = $value['target'] ?? '';
        if (!in_array($target, ['', '_blank'], true)) {
            return $this->reject($path . ' > target must be empty or _blank.');
        }

        $title = $value['title'] ?? '';
        if (!is_string($title)) {
            return $this->reject($path . ' > title must be text.');
        }

        return ['url' => trim($url), 'title' => sanitize_text_field($title), 'target' => $target];
    }

    public static function isHttpUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f\\\\<>"\'`]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    public static function isLocalLink(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        return (str_starts_with($url, '/') && !str_starts_with($url, '//')) || (str_starts_with($url, '#') && strlen($url) > 1);
    }

    private function attachmentId(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }

    private function image(mixed $value, string $path): ?int
    {
        $id = $this->attachmentId($value);
        $post = $id === null ? null : get_post($id);

        if ($id === null || !$post || $post->post_type !== 'attachment' || !str_starts_with((string) $post->post_mime_type, 'image/')) {
            return $this->reject($path . ' must be the ID of an image in the media library.');
        }

        return $id;
    }

    /** @return list<int>|null */
    private function images(mixed $value, string $path, int $min): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_IMAGES) {
            return $this->reject(sprintf('%s must be a list of at most %d image IDs.', $path, self::MAX_IMAGES));
        }

        $ids = [];
        foreach ($value as $item) {
            $id = $this->image($item, $path);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        if (count($ids) < $min && $this->errors === []) {
            return $this->reject(sprintf('%s needs at least %d images.', $path, $min));
        }

        return $ids === [] ? null : $ids;
    }

    /** @param array<string, mixed> $field */
    private function file(mixed $value, string $path, array $field): ?int
    {
        $id = $this->attachmentId($value);
        $post = $id === null ? null : get_post($id);
        /** @var list<string> $mime */
        $mime = $field['mime'] ?? [];
        $extension = $post ? strtolower((string) pathinfo((string) get_attached_file($post->ID), PATHINFO_EXTENSION)) : '';

        if ($id === null || !$post || $post->post_type !== 'attachment' || ($mime !== [] && !in_array($extension, $mime, true))) {
            return $this->reject(sprintf('%s must be the ID of a media library file of type %s.', $path, implode('/', $mime)));
        }

        return $id;
    }

    /** @param array<string, mixed> $field */
    private function choice(mixed $value, string $path, array $field): string|int|null
    {
        /** @var list<string> $choices */
        $choices = $field['choices'] ?? [];

        if ((!is_string($value) && !is_int($value)) || !in_array((string) $value, $choices, true)) {
            return $this->reject(sprintf('%s must be one of: %s.', $path, implode(', ', array_slice($choices, 0, 12)) . (count($choices) > 12 ? ', ...' : '')));
        }

        return (string) $value === '' ? null : $value;
    }

    private function boolean(mixed $value, string $path): ?int
    {
        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            return $this->reject($path . ' must be true or false.');
        }

        return in_array($value, [true, 1, '1'], true) ? 1 : 0;
    }

    /** @param array<string, mixed> $field */
    private function number(mixed $value, string $path, array $field): int|float|null
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return $this->reject($path . ' must be a number.');
        }

        $number = $value + 0;
        if ((isset($field['min']) && $number < $field['min']) || (isset($field['max']) && $number > $field['max'])) {
            return $this->reject(sprintf('%s must be between %s and %s.', $path, (string) ($field['min'] ?? '-'), (string) ($field['max'] ?? '-')));
        }

        return $number;
    }

    /** @param array<string, mixed> $field */
    private function term(mixed $value, string $path, array $field): ?int
    {
        $id = $this->attachmentId($value);

        if ($id === null || !term_exists($id, (string) $field['taxonomy'])) {
            return $this->reject($path . ' must be the ID of an existing term.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $field
     * @return list<array<string, mixed>>|null
     */
    private function rows(mixed $value, string $path, array $field, string $layoutName): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ROWS) {
            return $this->reject(sprintf('%s must be a list of at most %d rows.', $path, self::MAX_ROWS));
        }

        $layout = ['name' => $layoutName, 'fields' => $field['fields']];
        $rows = [];

        foreach ($value as $i => $row) {
            if (!is_array($row)) {
                $this->errors[] = sprintf('%s row %d must be an object.', $path, $i + 1);
                continue;
            }
            $clean = $this->row($layout, $row, sprintf('%s row %d', $path, $i + 1));
            unset($clean['acf_fc_layout']);
            $rows[] = $clean;
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * @param array<string, mixed> $field
     * @return list<array<string, mixed>>|null
     */
    private function modules(mixed $value, string $path, array $field): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ROWS) {
            return $this->reject(sprintf('%s must be a list of at most %d modules.', $path, self::MAX_ROWS));
        }

        /** @var array<string, array<string, mixed>> $modules */
        $modules = $field['modules'];
        $rows = [];

        foreach ($value as $i => $row) {
            $name = is_array($row) ? ($row['acf_fc_layout'] ?? null) : null;
            if (!is_array($row) || !is_string($name) || !isset($modules[$name])) {
                $this->errors[] = sprintf('%s module %d has no allowed acf_fc_layout.', $path, $i + 1);
                continue;
            }
            $rows[] = $this->row($modules[$name], $row, sprintf('%s module %d (%s)', $path, $i + 1, $name));
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * ACF validation with the theme's validators, then the contract's own sections check.
     *
     * @param array<int, array<string, mixed>> $own cleaned rows by their position in the input
     * @return list<string>
     */
    private function deepChecks(array $own): array
    {
        if ($own === []) {
            return [];
        }

        $positions = array_keys($own);
        $keyRows = [];
        $names = [];
        foreach ($own as $row) {
            $layout = $this->schema->find((string) $row['acf_fc_layout']);
            $keyRows[] = $layout === null ? [] : $this->toKeys($layout, $row, $names);
        }

        $problems = [];

        foreach (($this->acf)($this->contract->pageFieldKey(), $keyRows) as $error) {
            $problems[] = self::describe($error, $positions, $names);
        }

        foreach ($this->contract->validateSections(array_values($own)) as $problem) {
            $problems[] = $problem;
        }

        return $problems;
    }

    /**
     * Converts a name-keyed row to field keys, recursively, and records key => name.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $row
     * @param array<string, string> $names
     * @return array<string, mixed>
     */
    private function toKeys(array $layout, array $row, array &$names): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = $layout['fields'];
        $out = ['acf_fc_layout' => $layout['name']];

        foreach ($fields as $field) {
            $name = (string) $field['name'];
            if (!array_key_exists($name, $row)) {
                continue;
            }
            $key = (string) $field['key'];
            $names[$key] = $name;
            $value = $row[$name];

            if ($field['kind'] === 'rows' && is_array($value)) {
                $sub = ['name' => $layout['name'], 'fields' => $field['fields']];
                $converted = [];
                foreach ($value as $item) {
                    $keyed = $this->toKeys($sub, $item, $names);
                    unset($keyed['acf_fc_layout']);
                    $converted[] = $keyed;
                }
                $value = $converted;
            } elseif ($field['kind'] === 'modules' && is_array($value)) {
                /** @var array<string, array<string, mixed>> $modules */
                $modules = $field['modules'];
                $converted = [];
                foreach ($value as $item) {
                    $converted[] = $this->toKeys($modules[(string) $item['acf_fc_layout']], $item, $names);
                }
                $value = $converted;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array{input: string, message: string} $error
     * @param list<int> $positions
     * @param array<string, string> $names
     */
    private static function describe(array $error, array $positions, array $names): string
    {
        $path = '';
        if (preg_match_all('/\[([^\]]+)\]/', $error['input'], $m) > 0) {
            $parts = array_slice($m[1], 1);
            foreach ($parts as $i => $part) {
                if ($i === 0 && ctype_digit($part)) {
                    $path = 'Section ' . (($positions[(int) $part] ?? (int) $part) + 1);
                } elseif (ctype_digit($part) || str_starts_with($part, 'row-')) {
                    $path .= ' row ' . ((int) preg_replace('/\D/', '', $part) + 1);
                } else {
                    $path .= ' > ' . ($names[$part] ?? $part);
                }
            }
        }

        return trim($path . ': ' . $error['message'], ' :');
    }
}
