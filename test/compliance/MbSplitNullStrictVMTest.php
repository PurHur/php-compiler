<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mb_split(null) TypeError under strict_types (#29811, php-src php_mbregex.c). */
final class MbSplitNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_split_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_split_null_strict.phpt',
            'mb_split_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
