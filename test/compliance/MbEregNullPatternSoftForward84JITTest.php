<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mb_ereg/mb_eregi(null) soft-DEP then empty ValueError on 8.4 (#30067). */
final class MbEregNullPatternSoftForward84JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_ereg_null_pattern_soft_forward84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_ereg_null_pattern_soft_forward84_jit.phpt',
            'mb_ereg_null_pattern_soft_forward84_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
