<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DateTime/DateTimeImmutable setTimestamp/getLastErrors excess argc ArgumentCountError (#30991). */
final class DateTimeSetTimestampGetLastErrorsExcessArgc30991JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_settimestamp_getlasterrors_excess_argc_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_settimestamp_getlasterrors_excess_argc_jit.phpt',
            'datetime_settimestamp_getlasterrors_excess_argc_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
