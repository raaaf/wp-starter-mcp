<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Turns the contract's ACF layouts into a compact description Claude can use, writable layouts
 * only. The same descriptors drive SectionValidator, so what Claude is told is what is enforced.
 *
 * A field descriptor: name, key, kind, label, instructions, required, conditional, and by kind
 * choices (list of allowed values), min, max, fields (repeater), modules (nested layouts by
 * name), mime (allowed file extensions), taxonomy. Kind 'unsupported' means: refuse any value.
 *
 * @phpstan-type Field array<string, mixed>
 * @phpstan-type Layout array{key: string, name: string, label: string, fields: list<array<string, mixed>>}
 */
final class ModuleSchema
{
    /** ACF types without a value: they only structure the editing screen. */
    private const UI_TYPES = ['tab', 'accordion', 'message'];

    private const KINDS = [
        'text' => 'text',
        'textarea' => 'text',
        'wysiwyg' => 'html',
        'email' => 'email',
        'url' => 'url',
        'link' => 'link',
        'image' => 'image',
        'gallery' => 'images',
        'file' => 'file',
        'select' => 'choice',
        'radio' => 'choice',
        'button_group' => 'choice',
        'true_false' => 'boolean',
        'number' => 'number',
        'range' => 'number',
        'repeater' => 'rows',
        'flexible_content' => 'modules',
        'taxonomy' => 'term',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $layouts = null;

    public function __construct(private readonly Contract $contract)
    {
    }

    /**
     * Writable layouts by name. A name in the allowlist that the theme does not register is absent.
     *
     * @return array<string, array<string, mixed>>
     */
    public function layouts(): array
    {
        if ($this->layouts !== null) {
            return $this->layouts;
        }

        $writable = $this->contract->writable();
        $found = [];

        foreach ($this->contract->layouts() as $layout) {
            $name = $layout['name'] ?? null;
            if (is_string($name) && in_array($name, $writable, true)) {
                $found[$name] = $this->layout($layout);
            }
        }

        return $this->layouts = $found;
    }

    /** @return array<string, mixed>|null */
    public function find(string $name): ?array
    {
        return $this->layouts()[$name] ?? null;
    }

    /** @return list<array{name: string, label: string}> */
    public function summary(): array
    {
        $list = [];
        foreach ($this->layouts() as $layout) {
            $list[] = ['name' => (string) $layout['name'], 'label' => (string) $layout['label']];
        }

        return $list;
    }

    /** @return array<string, mixed>|null */
    public function compact(string $name): ?array
    {
        $layout = $this->find($name);

        return $layout === null ? null : self::compactLayout($layout);
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function layout(array $layout): array
    {
        return [
            'key' => (string) ($layout['key'] ?? ''),
            'name' => (string) $layout['name'],
            'label' => (string) ($layout['label'] ?? $layout['name']),
            'fields' => $this->fields(is_array($layout['sub_fields'] ?? null) ? $layout['sub_fields'] : []),
        ];
    }

    /**
     * @param array<mixed> $acfFields
     * @return list<array<string, mixed>>
     */
    private function fields(array $acfFields): array
    {
        $fields = [];

        foreach ($acfFields as $field) {
            if (!is_array($field) || in_array($field['type'] ?? '', self::UI_TYPES, true) || empty($field['name'])) {
                continue;
            }
            $fields[] = $this->field($field);
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function field(array $field): array
    {
        $type = (string) ($field['type'] ?? '');
        $kind = self::KINDS[$type] ?? 'unsupported';

        // A multi-value select or taxonomy stores lists; not offered.
        if (!empty($field['multiple']) || ($type === 'taxonomy' && ($field['field_type'] ?? '') !== 'select')) {
            $kind = 'unsupported';
        }

        $descriptor = [
            'name' => (string) $field['name'],
            'key' => (string) ($field['key'] ?? ''),
            'kind' => $kind,
            'label' => (string) ($field['label'] ?? $field['name']),
            'instructions' => (string) ($field['instructions'] ?? ''),
            'required' => !empty($field['required']),
            'conditional' => !empty($field['conditional_logic']),
        ];

        if ($kind === 'choice') {
            $choices = is_array($field['choices'] ?? null) ? array_keys($field['choices']) : [];
            $descriptor['choices'] = array_map('strval', $choices);
        }

        if ($kind === 'number') {
            foreach (['min', 'max'] as $bound) {
                if (isset($field[$bound]) && is_numeric($field[$bound])) {
                    $descriptor[$bound] = $field[$bound] + 0;
                }
            }
        }

        if ($kind === 'rows') {
            $descriptor['fields'] = $this->fields(is_array($field['sub_fields'] ?? null) ? $field['sub_fields'] : []);
            $descriptor['min'] = is_numeric($field['min'] ?? null) ? (int) $field['min'] : 0;
        }

        if ($kind === 'images' && is_numeric($field['min'] ?? null)) {
            $descriptor['min'] = (int) $field['min'];
        }

        if ($kind === 'file') {
            $mime = is_string($field['mime_types'] ?? null) ? $field['mime_types'] : '';
            $descriptor['mime'] = array_values(array_filter(array_map('trim', explode(',', strtolower($mime)))));
        }

        if ($kind === 'term') {
            $descriptor['taxonomy'] = (string) ($field['taxonomy'] ?? '');
        }

        if ($kind === 'modules') {
            $modules = [];
            $writable = $this->contract->writable();
            foreach (is_array($field['layouts'] ?? null) ? $field['layouts'] : [] as $layout) {
                // Default deny: a nested layout must be on the allowlist too.
                if (is_array($layout) && is_string($layout['name'] ?? null) && in_array($layout['name'], $writable, true)) {
                    $modules[$layout['name']] = $this->layout($layout);
                }
            }
            $descriptor['modules'] = $modules;
        }

        return $descriptor;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private static function compactLayout(array $layout): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = $layout['fields'];

        return [
            'name' => $layout['name'],
            'label' => $layout['label'],
            'fields' => array_values(array_filter(array_map([self::class, 'compactField'], $fields))),
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>|null
     */
    private static function compactField(array $field): ?array
    {
        if ($field['kind'] === 'unsupported') {
            return null;
        }

        $out = [
            'name' => $field['name'],
            'type' => $field['kind'],
            'label' => $field['label'],
        ];

        if ($field['required'] && !$field['conditional']) {
            $out['required'] = true;
        }
        if ($field['conditional']) {
            $out['only_for_some_variants'] = true;
        }
        if ($field['instructions'] !== '') {
            $out['help'] = mb_substr(wp_strip_all_tags((string) $field['instructions']), 0, 200);
        }

        foreach (['choices', 'min', 'max', 'mime', 'taxonomy'] as $key) {
            if (isset($field[$key]) && $field[$key] !== [] && $field[$key] !== 0) {
                $out[$key] = $field[$key];
            }
        }

        if ($field['kind'] === 'rows') {
            /** @var list<array<string, mixed>> $sub */
            $sub = $field['fields'];
            $out['fields'] = array_values(array_filter(array_map([self::class, 'compactField'], $sub)));
        }

        if ($field['kind'] === 'modules') {
            /** @var array<string, array<string, mixed>> $modules */
            $modules = $field['modules'];
            $out['modules'] = array_values(array_map([self::class, 'compactLayout'], $modules));
        }

        return $out;
    }
}
