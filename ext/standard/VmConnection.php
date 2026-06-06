<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Client connection bitmask (ext/standard/basic_functions.c — connection_status, issue #6161).
 *
 * CLI returns CONNECTION_NORMAL until web SAPI wires disconnect detection (#173, #3242).
 */
final class VmConnection
{
    public const NORMAL = 0;

    public const ABORTED = 1;

    public const TIMEOUT = 2;

    private static int $status = self::NORMAL;

    public static function reset(): void
    {
        self::$status = self::NORMAL;
    }

    public static function connectionStatus(): int
    {
        return self::$status;
    }

    /** @internal Web SAPI may set aborted/timeout bits when client disconnect is detected (#173). */
    public static function setStatus(int $status): void
    {
        self::$status = $status;
    }
}
