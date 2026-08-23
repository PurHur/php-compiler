<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ChildNode::after() append-tail saveXML keeps sibling (#34136).
 */
final class DomChildNodeAfterAppendTailSavexmlAotJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_childnode_after_append_tail_savexml_aot.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_childnode_after_append_tail_savexml_aot.phpt',
            'dom_childnode_after_append_tail_savexml_aot.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
