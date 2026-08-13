<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: array_fill/array_fill_keys/range ArgumentCountError wording (#30719). */
final class ArrayFillRangeExcessArgc30719JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_array_fill_range_30719_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_array_fill_range_30719_jit.phpt',
            'excess_argc_array_fill_range_30719_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
