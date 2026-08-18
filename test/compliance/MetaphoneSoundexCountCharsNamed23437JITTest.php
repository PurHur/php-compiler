<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: metaphone/soundex/count_chars Zend stub names + named args (#23437).
 */
final class MetaphoneSoundexCountCharsNamed23437JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'metaphone_soundex_count_chars_named_23437.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/metaphone_soundex_count_chars_named_23437.phpt',
            'metaphone_soundex_count_chars_named_23437.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
