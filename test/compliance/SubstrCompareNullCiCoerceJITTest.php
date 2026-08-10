<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT: substr_compare(null $case_insensitive) soft-null DEP+coerce (#29756). */
final class SubstrCompareNullCiCoerceJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_compare_null_ci_coerce_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_compare_null_ci_coerce_jit.phpt',
            'substr_compare_null_ci_coerce_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
