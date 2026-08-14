<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: createFromFormat invalid clock overflow (#30972). */
final class CreateFromFormatClockOverflow30972VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_create_from_format_clock_overflow.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetime_create_from_format_clock_overflow.phpt',
            'datetime_create_from_format_clock_overflow.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
