<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: timezone_abbreviations_list + DateTimeZone::listAbbreviations (#30780).
 *
 * @group llvm
 * @group jit
 */
final class TimezoneAbbreviationsListJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'timezone_abbreviations_list_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/timezone_abbreviations_list_jit.phpt',
            'timezone_abbreviations_list_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
