<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: date_parse_from_format(null) TypeError under strict_types (#30308). */
final class DateParseFromFormatNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_parse_from_format_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_parse_from_format_null_strict.phpt',
            'date_parse_from_format_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
