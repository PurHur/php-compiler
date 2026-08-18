<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: overflow numeric-string ⊙ int is IS_DOUBLE (#32432).
 *
 * @group llvm
 */
final class StrOverflowIsDouble32432JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_overflow_is_double.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/str_overflow_is_double.phpt',
            'str_overflow_is_double.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
