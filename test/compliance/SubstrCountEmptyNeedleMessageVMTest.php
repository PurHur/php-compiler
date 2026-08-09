<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: substr_count empty needle ValueError "must not be empty" (#29276, php-src string.c). */
final class SubstrCountEmptyNeedleMessageVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_count_empty_needle_must_not_be_empty.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_count_empty_needle_must_not_be_empty.phpt',
            'substr_count_empty_needle_must_not_be_empty.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
