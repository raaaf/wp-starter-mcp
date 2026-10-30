<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Reads a page's sections in the format Claude writes, and keeps what Claude cannot write.
 *
 * A stored row Claude cannot represent (layout not writable, field unknown to the schema,
 * unsupported field type) is returned as an opaque `keep` reference and copied server-side
 * on write, so it survives a round trip. A reference holds the row index and an HMAC of the
 * row, so it only resolves while that row is unchanged.
 */
final class PageContent
{
    public function __construct(
        private readonly Contract $contract,
        private readonly ModuleSchema $schema,
    ) {
    }

    /** @return list<array<string, mixed>> stored rows, field values unformatted */
    public function stored(int $postId): array
    {
        $rows = get_field($this->contract->pageField(), $postId, false);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    public function forClaude(int $postId): array
    {
        $sections = [];

        foreach ($this->stored($postId) as $index => $row) {
            $sections[] = $this->section($index, $row);
        }

        return $sections;
    }

    /**
     * Stored rows by keep reference, for resolving `keep` sections on update.
     *
     * @return array<string, array<string, mixed>>
     */
    public function keepable(int $postId): array
    {
        $map = [];
        foreach ($this->stored($postId) as $index => $row) {
            $map[self::ref($index, $row)] = $row;
        }

        return $map;
    }

    /** @param array<string, mixed> $row */
    public static function ref(int $index, array $row): string
    {
        return $index . '-' . substr(hash_hmac('sha256', (string) wp_json_encode($row), wp_salt('auth')), 0, 16);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function section(int $index, array $row): array
    {
        $name = (string) ($row['acf_fc_layout'] ?? '');
        $layout = $this->schema->find($name);

        if ($layout !== null) {
            try {
                return $this->row($layout, $row);
            } catch (Unrepresentable) {
                // falls through to keep
            }
        }

        return ['acf_fc_layout' => $name, 'keep' => self::ref($index, $row)];
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     * @throws Unrepresentable
     */
    private function row(array $layout, array $row): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = $layout['fields'];
        // ACF returns unformatted rows keyed by field key; names are accepted too.
        $known = array_merge(array_column($fields, 'name'), array_column($fields, 'key'));

        foreach ($row as $key => $value) {
            if ($key !== 'acf_fc_layout' && !in_array($key, $known, true) && !self::isBlank($value)) {
                throw new Unrepresentable();
            }
        }

        $out = ['acf_fc_layout' => $layout['name']];

        foreach ($fields as $field) {
            $stored = $row[(string) $field['key']] ?? $row[(string) $field['name']] ?? null;
            $value = self::isEmpty($stored) ? null : $this->value($field, $stored);

            if ($value !== null) {
                $out[(string) $field['name']] = $value;
            }
        }

        return $out;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }

    /** Also false and zero: a stray unknown field holding those loses nothing when dropped. */
    private static function isBlank(mixed $value): bool
    {
        return self::isEmpty($value) || $value === false || $value === 0 || $value === '0';
    }

    /**
     * @param array<string, mixed> $field
     * @throws Unrepresentable
     */
    private function value(array $field, mixed $value): mixed
    {
        switch ($field['kind']) {
            case 'text':
            case 'html':
            case 'email':
            case 'url':
                return is_scalar($value) ? (string) $value : throw new Unrepresentable();
            case 'choice':
                return is_scalar($value) ? (string) $value : throw new Unrepresentable();
            case 'link':
                if (!is_array($value)) {
                    throw new Unrepresentable();
                }

                return ($value['url'] ?? '') === '' ? null : [
                    'url' => (string) $value['url'],
                    'title' => (string) ($value['title'] ?? ''),
                    'target' => (string) ($value['target'] ?? ''),
                ];
            case 'image':
            case 'file':
            case 'term':
                return is_numeric($value) ? (int) $value : throw new Unrepresentable();
            case 'images':
                return is_array($value) ? array_map(static fn ($id): int => (int) $id, array_values($value)) : throw new Unrepresentable();
            case 'boolean':
                return self::isBlank($value) ? null : true;
            case 'number':
                return is_numeric($value) ? $value + 0 : throw new Unrepresentable();
            case 'rows':
                if (!is_array($value)) {
                    throw new Unrepresentable();
                }
                $layout = ['name' => '', 'fields' => $field['fields']];

                return array_map(function ($item) use ($layout): array {
                    if (!is_array($item)) {
                        throw new Unrepresentable();
                    }
                    $out = $this->row($layout, $item);
                    unset($out['acf_fc_layout']);

                    return $out;
                }, array_values($value));
            case 'modules':
                if (!is_array($value)) {
                    throw new Unrepresentable();
                }
                /** @var array<string, array<string, mixed>> $modules */
                $modules = $field['modules'];

                return array_map(function ($item) use ($modules): array {
                    $name = is_array($item) ? ($item['acf_fc_layout'] ?? null) : null;
                    if (!is_string($name) || !isset($modules[$name])) {
                        throw new Unrepresentable();
                    }

                    return $this->row($modules[$name], $item);
                }, array_values($value));
        }

        throw new Unrepresentable();
    }
}
