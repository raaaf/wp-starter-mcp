<?php

// Signatures of the Advanced Custom Fields functions the plugin calls. For PHPStan only, never loaded.

/** @return array<string, mixed>|false */
function acf_get_field(string $selector): array|false {}

/** @param array<string, mixed> $field */
function acf_validate_value(mixed $value, array $field, string $input): bool {}

function acf_reset_validation_errors(): void {}

/** @return array<int, array<string, mixed>>|false */
function acf_get_validation_errors(): array|false {}

function get_field(string $selector, int $postId, bool $format = true): mixed {}

function update_field(string $selector, mixed $value, int $postId): bool {}
