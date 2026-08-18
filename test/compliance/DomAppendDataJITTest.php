<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: appendData xmlTextConcat (#32376, ext/dom/characterdata.c).
 *
 * @group llvm
 */
final class DomAppendDataJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_appenddata.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_appenddata.phpt',
            'dom_appenddata.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
