<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for mb_strlen(). */
final class MbStrlenVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_strlen.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_strlen.phpt',
            'mb_strlen.phpt'
        );
        yield 'mb_strlen_enum_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_strlen_enum_typeerror.phpt',
            'mb_strlen_enum_typeerror.phpt'
        );
        yield 'mb_strlen_encoding.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_strlen_encoding.phpt',
            'mb_strlen_encoding.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
