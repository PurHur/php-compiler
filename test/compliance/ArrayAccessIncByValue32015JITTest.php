<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ArrayAccess ++ on by-value offsetGet — Notice, no offsetSet (#32015). */
final class ArrayAccessIncByValue32015JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'arrayaccess_inc_byvalue_offsetget.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/arrayaccess_inc_byvalue_offsetget.phpt',
            'arrayaccess_inc_byvalue_offsetget.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
