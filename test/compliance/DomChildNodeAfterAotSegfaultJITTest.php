<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DOM ChildNode::after() AOT segfault — parent is DOMDocument (#32611).
 */
final class DomChildNodeAfterAotSegfaultJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_childnode_after_aot_segfault.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_childnode_after_aot_segfault.phpt',
            'dom_childnode_after_aot_segfault.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
