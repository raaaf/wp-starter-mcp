<?php

declare(strict_types=1);

namespace WpStarterMcp;

/** Plain PHP views from views/, rendered with an explicit variable set. */
final class View
{
    /** @param array<string, mixed> $vars */
    public static function render(string $name, array $vars): void
    {
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        })(dirname(__DIR__) . '/views/' . $name . '.php', $vars);
    }
}
