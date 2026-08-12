<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mb_ereg/mb_eregi(null) soft-DEP then empty ValueError on 8.4 (#30067). */
final class MbEregNullPatternSoftForward84VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_ereg_null_pattern_soft_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_ereg_null_pattern_soft_forward84.phpt',
            'mb_ereg_null_pattern_soft_forward84.phpt'
        );
        yield 'mb_ereg_null_pattern_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_ereg_null_pattern_forward84.phpt',
            'mb_ereg_null_pattern_forward84.phpt'
        );
        yield 'mb_ereg_empty_pattern.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_ereg_empty_pattern.phpt',
            'mb_ereg_empty_pattern.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
