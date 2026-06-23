<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for similar_text(). */
final class SimilarTextVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'similar_text.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text.phpt',
            'similar_text.phpt'
        );
        yield 'similar_text_percent.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_percent.phpt',
            'similar_text_percent.phpt'
        );
        yield 'similar_text_percent_undefined.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_percent_undefined.phpt',
            'similar_text_percent_undefined.phpt'
        );
        yield 'similar_text_coerce.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_coerce.phpt',
            'similar_text_coerce.phpt'
        );
        yield 'similar_text_type_error.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_type_error.phpt',
            'similar_text_type_error.phpt'
        );
        yield 'similar_text_strict_types.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_strict_types.phpt',
            'similar_text_strict_types.phpt'
        );
        yield 'similar_text_dual_str_repeat.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/similar_text_dual_str_repeat.phpt',
            'similar_text_dual_str_repeat.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
