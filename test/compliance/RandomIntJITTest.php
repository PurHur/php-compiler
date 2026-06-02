<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for random_int() (#2330).
 *
 * @group llvm
 */
final class RandomIntJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'random_int_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/random_int_jit.phpt',
            'random_int_jit.phpt'
        );
        yield 'random_int_invalid_range_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/random_int_invalid_range_jit.phpt',
            'random_int_invalid_range_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
