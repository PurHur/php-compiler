<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/**
 * date_create()/DateTime::__construct() $datetime — typed string default "now" (ext/date/php_date.c; #18730).
 *
 * PHP 8.2: null deprecated and coerces to "" (now). PHP 8.4+ forward profile: null TypeError.
 */
final class VmDateTimeCreateArg
{
    public static function requiresStrictDatetimeType(): bool
    {
        return version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * @throws \TypeError when forward profile rejects null datetime operand
     */
    public static function coerceDatetime(
        Frame $frame,
        Variable $var,
        string $function,
        int $userArgIndex = 0,
        string $paramName = 'datetime'
    ): string {
        if (self::requiresStrictDatetimeType()) {
            InternalStrictArg::rejectNullString($var, $function, $paramName, $userArgIndex, $frame);
        }

        return VmString::coerceStringBuiltinArg($var, $function, $userArgIndex, $paramName);
    }

    /**
     * Compile-time null datetime literal — reject on 8.4+ profile, else "" (now).
     *
     * @throws \TypeError when forward profile rejects null
     */
    public static function jitNullDatetimeLiteral(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex = 0,
        string $paramName = 'datetime'
    ): string {
        if (self::requiresStrictDatetimeType()) {
            JitInternalStrictArg::rejectNullString($context, $arg, $function, $paramName, $userArgIndex + 1);
        }

        return '';
    }
}
