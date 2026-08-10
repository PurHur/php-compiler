<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime(Immutable)::createFromFormat(null) TypeError under strict_types (#29830). */
final class DateTimeCreateFromFormatNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_createfromformat_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_createfromformat_null_strict.phpt',
            'datetime_createfromformat_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
