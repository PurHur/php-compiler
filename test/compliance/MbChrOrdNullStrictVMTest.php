<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mb_chr/mb_ord(null) TypeError under strict_types (#29778, php-src mbstring.c). */
final class MbChrOrdNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_chr_ord_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_chr_ord_null_strict.phpt',
            'mb_chr_ord_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
