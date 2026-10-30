<?php

declare(strict_types=1);

namespace WpStarterMcp;

/**
 * Per-user connection intent. The setup page creates it when the user clicks "Start
 * connection"; the consent screen refuses without a fresh one. This stops consent phishing.
 */
final class Intent
{
    private const META = 'wpsmcp_intent';

    private readonly \Closure $clock;

    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function create(int $userId): void
    {
        update_user_meta($userId, self::META, ($this->clock)());
    }

    public function isFresh(int $userId): bool
    {
        $at = (int) get_user_meta($userId, self::META, true);

        return $at > 0 && ($this->clock)() - $at < Config::INTENT_TTL;
    }

    /** One intent covers one connection. */
    public function consume(int $userId): bool
    {
        if (!$this->isFresh($userId)) {
            return false;
        }

        delete_user_meta($userId, self::META);

        return true;
    }
}
