<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime/DateTimeZone/DateInterval methods excess argc → ArgumentCountError (#30834). */
final class DateTimeMethodsExcessArgc30834VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_datetime_methods_30834.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_datetime_methods_30834.phpt',
            'excess_argc_datetime_methods_30834.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
