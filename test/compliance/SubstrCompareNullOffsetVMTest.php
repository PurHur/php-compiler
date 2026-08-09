<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: substr_compare(null $offset) soft-null DEP+coerce (#29504, php-src string.c). */
final class SubstrCompareNullOffsetVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_compare_null_offset.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_compare_null_offset.phpt',
            'substr_compare_null_offset.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
