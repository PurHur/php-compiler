<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime::setTimezone(null) TypeError Argument #1 ($timezone) (#29869). */
final class DateTimeSetTimezoneNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_settimezone_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_settimezone_null_strict.phpt',
            'datetime_settimezone_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
