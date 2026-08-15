<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: array_filter null $mode under strict_types → TypeError (#31360). */
final class ArrayFilterNullModeStrict31360JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_filter_null_mode_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_filter_null_mode_strict_jit.phpt',
            'array_filter_null_mode_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
