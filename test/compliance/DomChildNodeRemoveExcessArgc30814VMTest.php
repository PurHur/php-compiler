<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ChildNode::remove() excess argc → ArgumentCountError (#30814). */
final class DomChildNodeRemoveExcessArgc30814VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dom_childnode_remove_30814.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_dom_childnode_remove_30814.phpt',
            'excess_argc_dom_childnode_remove_30814.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
