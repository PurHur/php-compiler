<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: timezone_abbreviations_list excess argc → ArgumentCountError (#30681). */
final class TimezoneAbbreviationsListExcessArgc30681JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_timezone_abbreviations_list_30681_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_timezone_abbreviations_list_30681_jit.phpt',
            'excess_argc_timezone_abbreviations_list_30681_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
