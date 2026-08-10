<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: substr_compare(null $case_insensitive) under strict_types TypeError (#29756). */
final class SubstrCompareNullCiStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_compare_null_ci_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_compare_null_ci_strict.phpt',
            'substr_compare_null_ci_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
