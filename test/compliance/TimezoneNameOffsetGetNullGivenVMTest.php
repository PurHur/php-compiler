<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: timezone_name_get/timezone_offset_get(null) TypeError includes ", null given" (#29878). */
final class TimezoneNameOffsetGetNullGivenVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'timezone_name_offset_get_null_given.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/timezone_name_offset_get_null_given.phpt',
            'timezone_name_offset_get_null_given.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
