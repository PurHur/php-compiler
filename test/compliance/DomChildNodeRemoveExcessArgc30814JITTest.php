<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ChildNode::remove() excess argc → ArgumentCountError (#30814). */
final class DomChildNodeRemoveExcessArgc30814JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dom_childnode_remove_30814_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_dom_childnode_remove_30814_jit.phpt',
            'excess_argc_dom_childnode_remove_30814_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
