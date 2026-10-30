<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Fixed-window counter in transients. Keys are hashed. Callers key by token family or code
 * hash, because all Claude traffic shares Anthropic's addresses; an address key is only a
 * high cap for garbage. Not atomic: a burst may overshoot slightly, which is acceptable here.
 */
final class RateLimiter
{
    private readonly \Closure $clock;

    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function allow(string $key, int $max, int $window = 60): bool
    {
        $now = ($this->clock)();
        $name = 'wpsmcp_rl_' . substr(hash('sha256', $key), 0, 40);
        $state = get_transient($name);

        if (!is_array($state) || $now - (int) ($state['start'] ?? 0) >= $window) {
            $state = ['start' => $now, 'hits' => 0];
        }

        $state['hits'] = (int) $state['hits'] + 1;
        set_transient($name, $state, $window);

        return $state['hits'] <= $max;
    }
}
