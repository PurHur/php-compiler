<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: stripcslashes() ArgumentCountError wording (#30704). */
final class StripcslashesExcessArgc30704JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_stripcslashes_30704_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_stripcslashes_30704_jit.phpt',
            'excess_argc_stripcslashes_30704_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
