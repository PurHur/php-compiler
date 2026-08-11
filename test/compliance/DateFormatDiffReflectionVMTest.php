<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: date_format/date_diff Reflection DateTimeInterface + string return (#30245). */
final class DateFormatDiffReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_format_diff_reflection.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/date_format_diff_reflection.phpt',
            'date_format_diff_reflection.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
