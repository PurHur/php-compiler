<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT: substr_replace(null $replace) under strict_types TypeError (#29874). */
final class SubstrReplaceNullReplaceStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_replace_null_replace_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_replace_null_replace_strict_jit.phpt',
            'substr_replace_null_replace_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
