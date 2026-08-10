<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: missing trait Fatal quotes + Fatal error framing (#30012, zend_compile.c).
 */
require_once __DIR__.'/../BaseTest.php';

final class MissingTraitFatalQuotes30012JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'missing_trait_fatal_quotes.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/missing_trait_fatal_quotes.phpt',
            'missing_trait_fatal_quotes.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
