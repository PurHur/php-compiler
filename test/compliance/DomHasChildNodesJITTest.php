<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: hasChildNodes xmlNode->children (ext/dom/node.c) (#32427).
 *
 * @group llvm
 */
final class DomHasChildNodesJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_haschildnodes.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_haschildnodes.phpt',
            'dom_haschildnodes.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
