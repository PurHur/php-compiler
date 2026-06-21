<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for similar_text(). */
final class SimilarTextJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'similar_text_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_jit.phpt',
            'similar_text_jit.phpt'
        );
        yield 'similar_text_percent.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_percent.phpt',
            'similar_text_percent.phpt'
        );
        yield 'similar_text_percent_undefined.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_percent_undefined.phpt',
            'similar_text_percent_undefined.phpt'
        );
        yield 'similar_text_coerce_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_coerce_jit.phpt',
            'similar_text_coerce_jit.phpt'
        );
        yield 'similar_text_strict_types_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_strict_types_jit.phpt',
            'similar_text_strict_types_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
