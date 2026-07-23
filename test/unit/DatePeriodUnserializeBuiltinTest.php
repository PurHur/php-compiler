<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for DatePeriod serialize round-trip (#22447). */
final class DatePeriodUnserializeBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/../compliance/cases/date/dateperiod_unserialize.phpt';
        yield 'dateperiod_unserialize.phpt' => self::parsePHPT($path, 'dateperiod_unserialize.phpt');
    }
}
