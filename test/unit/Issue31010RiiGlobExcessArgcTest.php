<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RecursiveIteratorIterator / GlobIterator residual excess argc (#31010).
 *
 * php-src: ext/spl/spl_iterators.c / spl_directory.c
 */
final class Issue31010RiiGlobExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31010_rii_glob_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31010_rii_glob_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'rewind: ArgumentCountError: RecursiveIteratorIterator::rewind() expects exactly 0 arguments, 1 given',
            'next: ArgumentCountError: RecursiveIteratorIterator::next() expects exactly 0 arguments, 1 given',
            'key: ArgumentCountError: RecursiveIteratorIterator::key() expects exactly 0 arguments, 1 given',
            'current: ArgumentCountError: RecursiveIteratorIterator::current() expects exactly 0 arguments, 1 given',
            'valid: ArgumentCountError: RecursiveIteratorIterator::valid() expects exactly 0 arguments, 1 given',
            'getMaxDepth: ArgumentCountError: RecursiveIteratorIterator::getMaxDepth() expects exactly 0 arguments, 1 given',
            'beginIteration: ArgumentCountError: RecursiveIteratorIterator::beginIteration() expects exactly 0 arguments, 1 given',
            'endIteration: ArgumentCountError: RecursiveIteratorIterator::endIteration() expects exactly 0 arguments, 1 given',
            'callHasChildren: ArgumentCountError: RecursiveIteratorIterator::callHasChildren() expects exactly 0 arguments, 1 given',
            'callGetChildren: ArgumentCountError: RecursiveIteratorIterator::callGetChildren() expects exactly 0 arguments, 1 given',
            'count: ArgumentCountError: GlobIterator::count() expects exactly 0 arguments, 1 given',
            'rewind_ok: OK',
            'valid_ok: OK',
            'getMaxDepth_ok: OK',
            'count_ok: OK',
        ] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
