<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: cloneNode saveXML (ext/dom/node.c xmlDocCopyNode) (#32355).
 *
 * @group llvm
 */
final class DomCloneNodeJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_clonenode_savexml.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_clonenode_savexml.phpt',
            'dom_clonenode_savexml.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
