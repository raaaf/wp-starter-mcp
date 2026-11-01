<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Warning-and-above events go to error_log. Only allowlisted identifiers are written,
 * never tokens, codes, verifiers or state.
 */
final class Logger
{
    private const ALLOWED = ['family', 'user', 'client_host'];

    /** @param array<string, string|int> $context */
    public static function warn(string $event, array $context = []): void
    {
        $safe = array_intersect_key($context, array_flip(self::ALLOWED));
        error_log('wpsmcp: ' . $event . ' ' . (string) wp_json_encode($safe));
    }
}
