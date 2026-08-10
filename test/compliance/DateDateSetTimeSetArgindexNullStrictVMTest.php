<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: date_date_set/date_time_set(null) TypeError cites Argument #2 (#29863). */
final class DateDateSetTimeSetArgindexNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_date_set_time_set_argindex_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_date_set_time_set_argindex_null_strict.phpt',
            'date_date_set_time_set_argindex_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
