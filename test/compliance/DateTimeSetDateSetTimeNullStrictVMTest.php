<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime::setDate/setTime(null) TypeError under strict_types (#29829). */
final class DateTimeSetDateSetTimeNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_setdate_settime_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_setdate_settime_null_strict.phpt',
            'datetime_setdate_settime_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
