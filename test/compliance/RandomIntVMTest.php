<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for random_int() (#2330). */
final class RandomIntVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'random_int.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/random_int.phpt',
            'random_int.phpt'
        );
        yield 'random_int_invalid_range.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/random_int_invalid_range.phpt',
            'random_int_invalid_range.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
