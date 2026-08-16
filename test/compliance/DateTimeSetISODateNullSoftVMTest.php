<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime::setISODate(null,…) soft-null formats -0001-12-26 (#31620). */
final class DateTimeSetISODateNullSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_setisodate_null_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_setisodate_null_soft.phpt',
            'datetime_setisodate_null_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
