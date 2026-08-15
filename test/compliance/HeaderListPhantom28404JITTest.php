<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: header_list() phantom — absent from php-src (#28404, re-#12546).
 */
final class HeaderListPhantom28404JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'header_list_phantom.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/header_list_phantom.phpt',
            'header_list_phantom.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
