<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateInterval::__construct excess argc → ArgumentCountError (#30601). */
final class DateIntervalCtorExcessArgc30601VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dateinterval_ctor_30601.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_dateinterval_ctor_30601.phpt',
            'excess_argc_dateinterval_ctor_30601.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
