<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: date_offset_get(null) TypeError single Argument #1 prefix (#29864). */
final class DateOffsetGetTypeerrorDupPrefixVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_offset_get_typeerror_dup_prefix.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_offset_get_typeerror_dup_prefix.phpt',
            'date_offset_get_typeerror_dup_prefix.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
