<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: timezone_abbreviations_list excess argc → ArgumentCountError (#30681). */
final class TimezoneAbbreviationsListExcessArgc30681VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_timezone_abbreviations_list_30681.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_timezone_abbreviations_list_30681.phpt',
            'excess_argc_timezone_abbreviations_list_30681.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
