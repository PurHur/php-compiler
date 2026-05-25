<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT compliance for ini_get() (#1374, #1492).
 */
final class IniGetJITTest extends BaseTest
{
    public static function provideCases(): iterable
    {
        yield 'ini_get_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/ini_get_jit.phpt',
            'ini_get_jit.phpt'
        );
    }
}
