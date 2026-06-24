<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for array_rand(). */
final class ArrayRandVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_rand.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_rand.phpt',
            'array_rand.phpt'
        );
        yield 'array_rand_validation.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_rand_validation.phpt',
            'array_rand_validation.phpt'
        );
        yield 'array_rand_num_numeric_string.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_rand_num_numeric_string.phpt',
            'array_rand_num_numeric_string.phpt'
        );
        yield 'array_rand_num_named.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_rand_num_named.phpt',
            'array_rand_num_named.phpt'
        );
        yield 'array_rand_string_num_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_rand_string_num_strict.phpt',
            'array_rand_string_num_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
