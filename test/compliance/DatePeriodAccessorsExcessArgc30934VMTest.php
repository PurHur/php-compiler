<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DatePeriod accessors excess argc ArgumentCountError (#30934). */
final class DatePeriodAccessorsExcessArgc30934VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dateperiod_accessors_30934.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_dateperiod_accessors_30934.phpt',
            'excess_argc_dateperiod_accessors_30934.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
