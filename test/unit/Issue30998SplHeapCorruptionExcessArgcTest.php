<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplHeap / SplPriorityQueue corruption + extract-flag excess argc (#30998).
 *
 * php-src: ext/spl/spl_heap.c
 */
final class Issue30998SplHeapCorruptionExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30998_splheap_corruption_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30998_splheap_corruption_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'h_isCorrupted: ArgumentCountError: SplHeap::isCorrupted() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'h_recoverFromCorruption: ArgumentCountError: SplHeap::recoverFromCorruption() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'q_setExtractFlags: ArgumentCountError: SplPriorityQueue::setExtractFlags() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'q_getExtractFlags: ArgumentCountError: SplPriorityQueue::getExtractFlags() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'q_isCorrupted: ArgumentCountError: SplPriorityQueue::isCorrupted() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'q_recoverFromCorruption: ArgumentCountError: SplPriorityQueue::recoverFromCorruption() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('h_isCorrupted_ok: OK false', $out);
        $this->assertStringContainsString('h_recover_ok: OK true', $out);
        $this->assertStringContainsString('q_set_ok: OK 3', $out);
        $this->assertStringContainsString('q_get_ok: OK 3', $out);
        $this->assertStringContainsString('q_isCorrupted_ok: OK false', $out);
        $this->assertStringContainsString('q_recover_ok: OK true', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
