<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: substr_count(null) DEP then empty ValueError on 8.4 (#29421, php-src string.c). */
final class SubstrCountNullNeedleForward84JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_count_null_needle_forward84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_count_null_needle_forward84_jit.phpt',
            'substr_count_null_needle_forward84_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
