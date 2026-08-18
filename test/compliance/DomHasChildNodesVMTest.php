<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: hasChildNodes xmlNode->children (ext/dom/node.c) (#32427).
 */
final class DomHasChildNodesVMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
