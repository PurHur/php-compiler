<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for mb_stripos()/mb_strrpos()/mb_strrichr() (#7015). */
final class MbStriposVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_stripos.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_stripos.phpt',
            'mb_stripos.phpt'
        );
        yield 'mb_stripos_enum_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_stripos_enum_typeerror.phpt',
            'mb_stripos_enum_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
