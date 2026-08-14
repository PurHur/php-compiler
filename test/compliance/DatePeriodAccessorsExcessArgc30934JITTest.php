<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DatePeriod accessors excess argc ArgumentCountError (#30934). */
final class DatePeriodAccessorsExcessArgc30934JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dateperiod_accessors_30934_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_dateperiod_accessors_30934_jit.phpt',
            'excess_argc_dateperiod_accessors_30934_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
