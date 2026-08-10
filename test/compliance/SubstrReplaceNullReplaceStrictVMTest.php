<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: substr_replace(null $replace) under strict_types TypeError (#29874). */
final class SubstrReplaceNullReplaceStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_replace_null_replace_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_replace_null_replace_strict.phpt',
            'substr_replace_null_replace_strict.phpt'
        );
        yield 'substr_replace_null_replace_weak.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_replace_null_replace_weak.phpt',
            'substr_replace_null_replace_weak.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
