<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for __compiler_hrtime_pair (#9182, php-in-PHP).
 *
 * php-src: ext/standard/hrtime.c
 * SSOT: ext/standard/VmHrtimeNative.php
 */
final class HrtimeJitHelper
{
    /**
     * @return array{0: int, 1: int}
     */
    public static function pair(): array
    {
        return VmHrtimeNative::readMonotonic();
    }
}
