<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: array_splice() float-string $offset Implicit conversion Deprecated (#29706).
 *
 * Dedicated provider — full JITTest discovery is heavy, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class ArraySpliceFloatStringOffsetDepJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_splice_float_string_offset_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_splice_float_string_offset_dep.phpt',
            'array_splice_float_string_offset_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }
}
