<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: getNodePath xmlGetNodePath (ext/dom/node.c) (#32474).
 */
final class DomGetNodePathJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_getnodepath.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_getnodepath.phpt',
            'dom_getnodepath.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
