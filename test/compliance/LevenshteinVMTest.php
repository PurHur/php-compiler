<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for levenshtein(). */
final class LevenshteinVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'levenshtein.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein.phpt',
            'levenshtein.phpt'
        );
        yield 'levenshtein_coerce.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_coerce.phpt',
            'levenshtein_coerce.phpt'
        );
        yield 'levenshtein_numeric_cost.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_numeric_cost.phpt',
            'levenshtein_numeric_cost.phpt'
        );
        yield 'levenshtein_negative_cost.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_negative_cost.phpt',
            'levenshtein_negative_cost.phpt'
        );
        yield 'levenshtein_inline_str_repeat.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_inline_str_repeat.phpt',
            'levenshtein_inline_str_repeat.phpt'
        );
        yield 'levenshtein_named_cost_params.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_named_cost_params.phpt',
            'levenshtein_named_cost_params.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
