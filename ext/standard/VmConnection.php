<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\BackedEnum;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * Client connection bitmask (ext/standard/basic_functions.c — connection_status, issue #6161, #7234).
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

    public static function assignStatusResult(Variable $dest, ?Context $ctx): void
    {
        // php-src ext/standard/basic_functions.c returns int bitmask, not ConnectionStatus enum (#9330).
        $dest->int(self::connectionStatus());
    }

    public static function enumCaseForStatus(?Context $ctx, int $status): ?Variable
    {
        if (null === $ctx || !isset($ctx->classes['connectionstatus'])) {
            return null;
        }
        $enum = $ctx->classes['connectionstatus'];
        $needle = new Variable(Variable::TYPE_INTEGER);
        $needle->int($status);
        $match = BackedEnum::tryCaseForValue($enum, $needle);
        if (null === $match) {
            return null;
        }
        $canonical = BackedEnum::canonicalCaseVariable($enum, $match->caseName);
        if (null !== $canonical) {
            return $canonical;
        }

        return EnumCaseSupport::createCase($enum, $match->caseName, $match->backingValue);
    }

    /** @internal Web SAPI may set aborted/timeout bits when client disconnect is detected (#173). */
    public static function setStatus(int $status): void
    {
        self::$status = $status;
    }
}
