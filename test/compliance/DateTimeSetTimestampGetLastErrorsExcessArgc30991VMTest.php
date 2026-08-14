<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime/DateTimeImmutable setTimestamp/getLastErrors excess argc ArgumentCountError (#30991). */
final class DateTimeSetTimestampGetLastErrorsExcessArgc30991VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_settimestamp_getlasterrors_excess_argc.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_settimestamp_getlasterrors_excess_argc.phpt',
            'datetime_settimestamp_getlasterrors_excess_argc.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
