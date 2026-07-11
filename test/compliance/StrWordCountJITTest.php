<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for str_word_count(). */
final class StrWordCountJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }

    public static function providePHPTests(): \Generator
    {
        yield 'str_word_count_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_word_count_jit.phpt',
            'str_word_count_jit.phpt'
        );
        yield 'str_word_count_format1_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_word_count_format1_jit.phpt',
            'str_word_count_format1_jit.phpt'
        );
        yield 'str_word_count_format2_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_word_count_format2_jit.phpt',
            'str_word_count_format2_jit.phpt'
        );
    }
}
