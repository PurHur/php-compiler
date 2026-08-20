<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: str_word_count Zend stub named params (#23920). */
final class StrWordCountNamed23920JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_word_count_named_23920_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_word_count_named_23920_jit.phpt',
            'str_word_count_named_23920_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
