<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: $arr[$float] ?? / ??= — Implicit conversion Deprecated once when set (#29664).
 *
 * Dedicated provider — full JITTest discovery is heavy, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class FloatDimCoalesceDepJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'float_dim_coalesce_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/float_dim_coalesce_dep.phpt',
            'float_dim_coalesce_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }
}
