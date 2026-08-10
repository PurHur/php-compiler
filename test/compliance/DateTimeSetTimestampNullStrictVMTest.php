<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime::setTimestamp(null) TypeError under strict_types (#29841). */
final class DateTimeSetTimestampNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_settimestamp_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_settimestamp_null_strict.phpt',
            'datetime_settimestamp_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
