<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: numeric-string ⊙ native float (#32325).
 *
 * @group llvm
 */
final class NumericStringFloatArith32325JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'numeric_string_float_arith.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/numeric_string_float_arith.phpt',
            'numeric_string_float_arith.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
