<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for array_count_values() (#2356).
 *
 * @group llvm
 */
final class ArrayCountValuesJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_count_values_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_count_values_jit.phpt',
            'array_count_values_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
