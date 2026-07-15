<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for parse_ini_string() (#11025, #9153). */
final class ParseIniBoolKeywordVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'parse_ini_bool_keyword_key.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/parse_ini_bool_keyword_key.phpt',
            'parse_ini_bool_keyword_key.phpt'
        );
        yield 'parse_ini_scanner_typed.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/parse_ini_scanner_typed.phpt',
            'parse_ini_scanner_typed.phpt'
        );
        yield 'parse_ini_loop.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/parse_ini_loop.phpt',
            'parse_ini_loop.phpt'
        );
        yield 'parse_ini_file_syntax_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/parse_ini_file_syntax_warning.phpt',
            'parse_ini_file_syntax_warning.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
