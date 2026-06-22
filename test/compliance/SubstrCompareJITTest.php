<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for substr_compare(). */
final class SubstrCompareJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_compare_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_compare_jit.phpt',
            'substr_compare_jit.phpt'
        );
        yield 'substr_compare_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_compare_typeerror_jit.phpt',
            'substr_compare_typeerror_jit.phpt'
        );
        yield 'substr_compare_explicit_length_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_compare_explicit_length_jit.phpt',
            'substr_compare_explicit_length_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
