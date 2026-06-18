<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for (int)/(float) cast on backed enum cases (#5714). */
final class IntCastBackedEnumVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'int_cast_backed_enum.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/int_cast_backed_enum.phpt',
            'int_cast_backed_enum.phpt'
        );
        yield 'enum_int_cast_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_int_cast_warning.phpt',
            'enum_int_cast_warning.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
