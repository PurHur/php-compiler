<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: boxed null ⊙ numeric-string (#32406).
 *
 * @group llvm
 */
final class ValueStringArith32406JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'value_string_arith.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/value_string_arith.phpt',
            'value_string_arith.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
