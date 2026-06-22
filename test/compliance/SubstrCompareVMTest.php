<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for substr_compare(). */
final class SubstrCompareVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_compare.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_compare.phpt',
            'substr_compare.phpt'
        );
        yield 'substr_compare_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_compare_typeerror.phpt',
            'substr_compare_typeerror.phpt'
        );
        yield 'substr_compare_explicit_length.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_compare_explicit_length.phpt',
            'substr_compare_explicit_length.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
