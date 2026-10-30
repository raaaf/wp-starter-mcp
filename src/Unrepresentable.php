<?php

declare(strict_types=1);

namespace WpStarterMcp;

/** A stored row or value that the compact format cannot hold; the row is kept instead. */
final class Unrepresentable extends \RuntimeException
{
}
