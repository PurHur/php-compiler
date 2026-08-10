<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mb_split(null) TypeError under strict_types (#29811, php-src php_mbregex.c). */
final class MbSplitNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_split_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_split_null_strict_jit.phpt',
            'mb_split_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
