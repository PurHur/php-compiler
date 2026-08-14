<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime/DateTimeImmutable::setISODate() excess argc ArgumentCountError (#30992). */
final class DateTimeSetISODateExcessArgc30992VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_setisodate_excess_argc.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_setisodate_excess_argc.phpt',
            'datetime_setisodate_excess_argc.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
