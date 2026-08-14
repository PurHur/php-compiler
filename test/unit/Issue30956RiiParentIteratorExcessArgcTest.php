<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RecursiveIteratorIterator / ParentIterator excess argc (#30956).
 *
 * php-src: ext/spl/spl_iterators.c
 */
final class Issue30956RiiParentIteratorExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30956_rii_parentiterator_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30956_rii_parentiterator_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'depth: ArgumentCountError: RecursiveIteratorIterator::getDepth() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'max: ArgumentCountError: RecursiveIteratorIterator::setMaxDepth() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'sub: ArgumentCountError: RecursiveIteratorIterator::getSubIterator() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'accept: ArgumentCountError: ParentIterator::accept() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'has: ArgumentCountError: RecursiveFilterIterator::hasChildren() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('depth_ok: OK', $out);
        $this->assertStringContainsString('max_ok: OK', $out);
        $this->assertStringContainsString('sub_ok: OK', $out);
        $this->assertStringContainsString('accept_ok: OK', $out);
        $this->assertStringContainsString('has_ok: OK', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
