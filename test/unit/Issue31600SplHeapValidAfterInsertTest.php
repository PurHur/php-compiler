<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplHeap/SplMaxHeap/SplMinHeap valid()/key() after insert matches Zend (#31600).
 *
 * php-src: ext/spl/spl_heap.c — spl_heap_it_valid / spl_heap_it_get_current_key
 */
final class Issue31600SplHeapValidAfterInsertTest extends TestCase
{
    public function testVmMatchesZendValidKeyAndNextWithoutRewind(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_splheap_valid_after_insert.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_splheap_valid_after_insert.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("valid=1 key=1\nafter_next count=1 cur=1\n", $out);
    }
}
