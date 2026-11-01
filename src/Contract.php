<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * The theme's answer to the filter `wp_starter_mcp_contract`. The plugin knows nothing else
 * about the theme. Without a contract of a known version everything fails closed: endpoints
 * 404 and the setup page names the reason. A missing key or a throwing callback denies,
 * never a fatal error.
 *
 * Version 1 is never dropped: an auto-update would otherwise 404 every unmerged fork.
 */
final class Contract
{
    public const FILTER = 'wp_starter_mcp_contract';
    public const KNOWN_VERSIONS = [1];

    private const CALLABLES = [
        'layouts', 'is_readable', 'after_write', 'is_allowed_embed_host', 'design_tokens', 'validate_sections',
    ];

    private static ?self $loaded = null;
    private static string $problem = 'not_loaded';

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /** The usable contract, or null. Cached once the theme had its chance to answer. */
    public static function current(): ?self
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        // rest_url() and theme providers are not ready earlier; a negative answer must not stick.
        if (!did_action('after_setup_theme')) {
            self::$problem = 'not_loaded';

            return null;
        }

        try {
            $raw = apply_filters(self::FILTER, null);
        } catch (\Throwable) {
            $raw = null;
        }

        $result = self::parse($raw);
        if (is_string($result)) {
            self::$problem = $result;

            return null;
        }

        self::$problem = '';

        return self::$loaded = $result;
    }

    public static function reset(): void
    {
        self::$loaded = null;
        self::$problem = 'not_loaded';
    }

    /** Machine reason why the contract is unusable, empty when it is fine. */
    public static function problem(): string
    {
        self::current();

        return self::$problem;
    }

    /** Plain-language reason for the setup page. */
    public static function message(): string
    {
        return match (self::problem()) {
            '' => '',
            'no_contract' => __('Das aktive Theme unterstützt die Claude-Anbindung nicht. Es fehlt die Schnittstelle "wp_starter_mcp_contract". Bei einem Client-Theme einmal die Theme-Datei src/Mcp/ContractProvider.php aus wp-starter übernehmen.', 'wp-starter-mcp'),
            'unknown_version' => __('Das aktive Theme liefert eine Schnittstellen-Version, die dieses Plugin nicht kennt. Bitte das Plugin aktualisieren.', 'wp-starter-mcp'),
            'invalid' => __('Die Schnittstelle des aktiven Themes ist unvollständig oder fehlerhaft. Bitte das Theme aktualisieren.', 'wp-starter-mcp'),
            default => __('Das Theme ist noch nicht geladen.', 'wp-starter-mcp'),
        };
    }

    /** @return self|string the contract, or a problem code */
    public static function parse(mixed $raw): self|string
    {
        if (!is_array($raw) || !isset($raw['version'])) {
            return 'no_contract';
        }

        if (!in_array($raw['version'], self::KNOWN_VERSIONS, true)) {
            return 'unknown_version';
        }

        foreach (['page_field', 'page_field_key'] as $key) {
            if (!isset($raw[$key]) || !is_string($raw[$key]) || $raw[$key] === '') {
                return 'invalid';
            }
        }

        foreach (['writable', 'copy_meta'] as $key) {
            if (!isset($raw[$key]) || !is_array($raw[$key]) || array_filter($raw[$key], 'is_string') !== $raw[$key]) {
                return 'invalid';
            }
        }

        foreach (self::CALLABLES as $key) {
            if (!isset($raw[$key]) || !is_callable($raw[$key])) {
                return 'invalid';
            }
        }

        return new self($raw);
    }

    public function pageField(): string
    {
        return (string) $this->data['page_field'];
    }

    public function pageFieldKey(): string
    {
        return (string) $this->data['page_field_key'];
    }

    /** @return list<string> */
    public function writable(): array
    {
        /** @var list<string> $writable */
        $writable = array_values((array) $this->data['writable']);

        return $writable;
    }

    /** @return list<string> */
    public function copyMeta(): array
    {
        /** @var list<string> $meta */
        $meta = array_values((array) $this->data['copy_meta']);

        return $meta;
    }

    /** @return list<array<string, mixed>> */
    public function layouts(): array
    {
        $layouts = $this->call('layouts', [], []);

        return is_array($layouts) ? array_values(array_filter($layouts, 'is_array')) : [];
    }

    public function isReadable(int $postId): bool
    {
        return $this->call('is_readable', [$postId], false) === true;
    }

    public function afterWrite(int $postId): void
    {
        $this->call('after_write', [$postId], null);
    }

    public function isAllowedEmbedHost(string $hostOrUrl): bool
    {
        return $this->call('is_allowed_embed_host', [$hostOrUrl], false) === true;
    }

    /** @return array<string, mixed> */
    public function designTokens(): array
    {
        $tokens = $this->call('design_tokens', [], []);

        return is_array($tokens) ? $tokens : [];
    }

    /**
     * @param array<mixed> $sections
     * @return list<string> problems, empty when valid; a failing callback is a problem
     */
    public function validateSections(array $sections): array
    {
        $problems = $this->call('validate_sections', [$sections], null);

        if (!is_array($problems)) {
            return ['The theme could not check the sections.'];
        }

        return array_values(array_map('strval', $problems));
    }

    /** @param list<mixed> $args */
    private function call(string $key, array $args, mixed $fallback): mixed
    {
        try {
            /** @var callable $callback */
            $callback = $this->data[$key];

            return $callback(...$args);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
