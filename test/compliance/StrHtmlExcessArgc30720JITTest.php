<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: str_word_count/htmlspecialchars_decode/get_html_translation_table ArgumentCountError wording (#30720). */
final class StrHtmlExcessArgc30720JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_str_html_30720_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_str_html_30720_jit.phpt',
            'excess_argc_str_html_30720_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
