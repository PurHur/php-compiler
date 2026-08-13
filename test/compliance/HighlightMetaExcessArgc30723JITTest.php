<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: highlight_string/get_meta_tags ArgumentCountError wording (#30723). */
final class HighlightMetaExcessArgc30723JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_highlight_meta_30723_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_highlight_meta_30723_jit.phpt',
            'excess_argc_highlight_meta_30723_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
