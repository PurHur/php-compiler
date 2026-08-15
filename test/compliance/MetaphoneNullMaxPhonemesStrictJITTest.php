<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: metaphone(null $max_phonemes) TypeError under strict_types (#31230). */
final class MetaphoneNullMaxPhonemesStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'metaphone_null_max_phonemes_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/metaphone_null_max_phonemes_strict_jit.phpt',
            'metaphone_null_max_phonemes_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
