<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime::diff $days decrements when later TOD is earlier (#32074). */
final class DateTimeDiffDaysTod32074VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_diff_days_tod.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetime_diff_days_tod.phpt',
            'datetime_diff_days_tod.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
