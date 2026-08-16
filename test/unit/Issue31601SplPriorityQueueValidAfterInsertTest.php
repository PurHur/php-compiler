<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplPriorityQueue valid()/key() after insert matches Zend (#31601).
 *
 * php-src: ext/spl/spl_heap.c — spl_heap_it_valid / spl_heap_it_get_current_key (pqueue)
 */
final class Issue31601SplPriorityQueueValidAfterInsertTest extends TestCase
{
    public function testVmMatchesZendValidKeyAndWhileIteration(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_splpriorityqueue_valid_after_insert.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_splpriorityqueue_valid_after_insert.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("valid=1 key=1\nb3;a1;\n", $out);
    }
}
