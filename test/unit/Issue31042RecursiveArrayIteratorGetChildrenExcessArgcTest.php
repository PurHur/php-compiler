<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RecursiveArrayIterator::getChildren excess argc (#31042).
 *
 * php-src: ext/spl/spl_array.c
 */
final class Issue31042RecursiveArrayIteratorGetChildrenExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31042_recursive_arrayiterator_getchildren_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31042_recursive_arrayiterator_getchildren_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'hasChildren: ArgumentCountError: RecursiveArrayIterator::hasChildren() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'getChildren: ArgumentCountError: RecursiveArrayIterator::getChildren() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
