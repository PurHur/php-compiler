<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/**
 * Lowered into JIT/AOT modules for __compiler_hrtime_pair (#9182, php-in-PHP).
 *
 * php-src: ext/standard/hrtime.c
 * SSOT: ext/standard/VmHrtimeNative.php
 */
final class HrtimeJitHelper
{
    private const NS_PER_SEC = 1_000_000_000;

    /**
     * @return array{0: int, 1: int}
     */
    public static function pair(): array
    {
        return VmHrtimeNative::readMonotonic();
    }

    /** php-src hrtime.c — RETURN_DOUBLE(sec * NS_PER_SEC + nsec) when as_number (#12779). */
    public static function nsFloat(): float
    {
        [$sec, $nsec] = VmHrtimeNative::readMonotonic();

        return (float) ($sec * self::NS_PER_SEC + $nsec);
    }

    /** php-src hrtime.c — RETURN_LONG on PHP 8.2 reference profile (#12789). */
    public static function nsInt(): int
    {
        [$sec, $nsec] = VmHrtimeNative::readMonotonic();

        return $sec * self::NS_PER_SEC + $nsec;
    }

    /** Profile-aware hrtime(true) scalar for compiled modules. */
    public static function nsAsNumber(): int|float
    {
        return CompilerVersion::supportsHrtimeAsNumberFloat()
            ? self::nsFloat()
            : self::nsInt();
    }
}
