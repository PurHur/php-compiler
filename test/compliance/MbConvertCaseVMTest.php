<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for mb_convert_case(). */
final class MbConvertCaseVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_convert_case.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_convert_case.phpt',
            'mb_convert_case.phpt'
        );
        yield 'mb_convert_case_enum_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_convert_case_enum_typeerror.phpt',
            'mb_convert_case_enum_typeerror.phpt'
        );
        yield 'mb_convert_case_invalid_mode.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_convert_case_invalid_mode.phpt',
            'mb_convert_case_invalid_mode.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
