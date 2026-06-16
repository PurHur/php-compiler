<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for settype() on backed enum cases (#5643). */
final class SettypeBackedEnumVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'settype_backed_enum.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/settype_backed_enum.phpt',
            'settype_backed_enum.phpt'
        );
        yield 'settype_backed_enum_int.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/settype_backed_enum_int.phpt',
            'settype_backed_enum_int.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
