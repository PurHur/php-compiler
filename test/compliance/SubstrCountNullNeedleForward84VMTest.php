<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: substr_count(null) DEP then empty ValueError on 8.4 (#29421, php-src string.c). */
final class SubstrCountNullNeedleForward84VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_count_null_needle_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_count_null_needle_forward84.phpt',
            'substr_count_null_needle_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
