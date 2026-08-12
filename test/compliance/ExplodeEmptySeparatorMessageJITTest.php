<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: explode('') ValueError "cannot be empty" (#29275, php-src string.c). */
final class ExplodeEmptySeparatorMessageJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'explode_empty_separator_must_not_be_empty_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/explode_empty_separator_must_not_be_empty_jit.phpt',
            'explode_empty_separator_must_not_be_empty_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
