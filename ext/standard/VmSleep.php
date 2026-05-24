<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** VM sleep/usleep via host PHP (parity with PHP 8.2). */
final class VmSleep
{
    public static function sleep(int $seconds): int|false
    {
        return \sleep($seconds);
    }

    public static function usleep(int $microseconds): void
    {
        \usleep($microseconds);
    }
}
