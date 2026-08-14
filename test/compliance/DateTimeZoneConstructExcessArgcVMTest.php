<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTimeZone::__construct() excess argc ArgumentCountError (#31068). */
final class DateTimeZoneConstructExcessArgcVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetimezone_construct_excess_argc.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetimezone_construct_excess_argc.phpt',
            'datetimezone_construct_excess_argc.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
