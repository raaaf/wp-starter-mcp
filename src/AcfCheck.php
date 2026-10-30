<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Runs ACF's own field validation, which applies the `acf/validate_value` filters the theme
 * registers (url, email, ...). The theme is never referenced.
 *
 * The rows must be keyed by field key: name-keyed rows are skipped silently by ACF. The
 * top-level return of acf_validate_value() stays true, so the error store is read instead.
 */
final class AcfCheck
{
    /**
     * @param array<int, array<string, mixed>> $rows rows keyed by field key
     * @return list<array{input: string, message: string}>
     */
    public static function run(string $pageFieldKey, array $rows): array
    {
        if (!function_exists('acf_get_field') || !function_exists('acf_validate_value')) {
            return [['input' => '', 'message' => 'ACF is not available.']];
        }

        $field = acf_get_field($pageFieldKey);
        if (!is_array($field)) {
            return [['input' => '', 'message' => 'The page field does not exist.']];
        }

        acf_reset_validation_errors();
        acf_validate_value($rows, $field, 'acf[' . $pageFieldKey . ']');

        $errors = [];
        // ACF returns false, not an empty list, when nothing failed.
        $found = acf_get_validation_errors();
        foreach (is_array($found) ? $found : [] as $error) {
            $errors[] = [
                'input' => (string) ($error['input'] ?? ''),
                'message' => (string) ($error['message'] ?? ''),
            ];
        }
        acf_reset_validation_errors();

        return $errors;
    }
}
