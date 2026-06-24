<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for parse_ini_string() bool keyword keys (#11025). */
final class ParseIniBoolKeywordVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'parse_ini_bool_keyword_key.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/parse_ini_bool_keyword_key.phpt',
            'parse_ini_bool_keyword_key.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
