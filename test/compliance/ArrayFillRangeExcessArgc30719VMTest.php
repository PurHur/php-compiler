<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: array_fill/array_fill_keys/range ArgumentCountError wording (#30719). */
final class ArrayFillRangeExcessArgc30719VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_array_fill_range_30719.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_array_fill_range_30719.phpt',
            'excess_argc_array_fill_range_30719.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
