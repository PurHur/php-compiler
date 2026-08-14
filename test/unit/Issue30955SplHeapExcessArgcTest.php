<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplHeap / SplPriorityQueue excess argc (#30955).
 *
 * php-src: ext/spl/spl_heap.c
 */
final class Issue30955SplHeapExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30955_splheap_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30955_splheap_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'top: ArgumentCountError: SplHeap::top() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'extract: ArgumentCountError: SplHeap::extract() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'isEmpty: ArgumentCountError: SplHeap::isEmpty() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'count: ArgumentCountError: SplHeap::count() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'valid: ArgumentCountError: SplHeap::valid() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'key: ArgumentCountError: SplHeap::key() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'current: ArgumentCountError: SplHeap::current() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'rewind: ArgumentCountError: SplHeap::rewind() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'next: ArgumentCountError: SplHeap::next() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'pq_top: ArgumentCountError: SplPriorityQueue::top() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'pq_cmp: ArgumentCountError: SplPriorityQueue::compare() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString('top_ok: OK', $out);
        $this->assertStringContainsString('count_ok: OK', $out);
        $this->assertStringContainsString('empty_ok: OK', $out);
        $this->assertStringContainsString('pq_top_ok: OK', $out);
        $this->assertStringContainsString('pq_cmp_ok: OK', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
