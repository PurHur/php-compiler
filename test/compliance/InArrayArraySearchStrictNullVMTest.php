<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: in_array/array_search(null $strict) under strict_types TypeError (#29866). */
final class InArrayArraySearchStrictNullVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'in_array_array_search_strict_null.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/in_array_array_search_strict_null.phpt',
            'in_array_array_search_strict_null.phpt'
        );
        yield 'in_array_array_search_strict_null_weak.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/in_array_array_search_strict_null_weak.phpt',
            'in_array_array_search_strict_null_weak.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
