<?php

declare(strict_types=1);

/**
 * VM memory introspection (host Zend allocator via VM host PHP, issue #3134).
 */

namespace PHPCompiler\ext\standard;

final class VmMemory
{
    public static function getUsage(bool $realUsage = false): int
    {
        return (int) \memory_get_usage($realUsage);
    }

    public static function getPeakUsage(bool $realUsage = false): int
    {
        return (int) \memory_get_peak_usage($realUsage);
    }
}
