<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: getLineNo xmlGetLineNo (ext/dom/node.c) (#32489).
 *
 * @group llvm
 */
final class DomGetLineNoJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_getlineno.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_getlineno.phpt',
            'dom_getlineno.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
