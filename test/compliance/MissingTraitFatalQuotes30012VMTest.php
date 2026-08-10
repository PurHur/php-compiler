<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: missing trait Fatal quotes + Fatal error framing (#30012, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class MissingTraitFatalQuotes30012VMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
