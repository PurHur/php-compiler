<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SplFixedArray ArrayAccess excess argc → ArgumentCountError (#30997). */
final class SplFixedArrayOffsetExcessArgc30997VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splfixedarray_offset_excess_argc_30997.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splfixedarray_offset_excess_argc_30997.phpt',
            'splfixedarray_offset_excess_argc_30997.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
