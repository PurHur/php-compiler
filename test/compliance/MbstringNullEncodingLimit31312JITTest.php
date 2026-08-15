<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mb_strtoupper/tolower null encoding + mb_split null limit (#31312). */
final class MbstringNullEncodingLimit31312JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_strtoupper_tolower_null_encoding.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_strtoupper_tolower_null_encoding.phpt',
            'mb_strtoupper_tolower_null_encoding.phpt'
        );
        yield 'mb_split_null_limit_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_split_null_limit_soft_dep.phpt',
            'mb_split_null_limit_soft_dep.phpt'
        );
        yield 'mb_split_null_limit_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_split_null_limit_strict.phpt',
            'mb_split_null_limit_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
