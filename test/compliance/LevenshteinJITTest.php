<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for levenshtein(). */
final class LevenshteinJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'levenshtein_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_jit.phpt',
            'levenshtein_jit.phpt'
        );
        yield 'levenshtein_enum_case_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_enum_case_jit.phpt',
            'levenshtein_enum_case_jit.phpt'
        );
        yield 'levenshtein_coerce_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_coerce_jit.phpt',
            'levenshtein_coerce_jit.phpt'
        );
        yield 'levenshtein_negative_cost_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_negative_cost_jit.phpt',
            'levenshtein_negative_cost_jit.phpt'
        );
        yield 'levenshtein_inline_str_repeat.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_inline_str_repeat.phpt',
            'levenshtein_inline_str_repeat.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }
}
