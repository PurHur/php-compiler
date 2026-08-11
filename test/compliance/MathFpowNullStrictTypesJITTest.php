<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: fpow(null) TypeError under strict_types (#30021). */
final class MathFpowNullStrictTypesJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'math_fpow_null_strict_types_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/math_fpow_null_strict_types_jit.phpt',
            'math_fpow_null_strict_types_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
