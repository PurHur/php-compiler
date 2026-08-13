<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SplFixedArray fromArray/toArray/setSize excess argc → ArgumentCountError (#30836). */
final class SplFixedArrayExcessArgc30836VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_splfixedarray_30836.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_splfixedarray_30836.phpt',
            'excess_argc_splfixedarray_30836.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
