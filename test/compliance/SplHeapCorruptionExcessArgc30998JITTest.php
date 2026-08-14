<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: SplHeap / SplPriorityQueue corruption + extract-flag excess argc (#30998). */
final class SplHeapCorruptionExcessArgc30998JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splheap_corruption_excess_argc.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splheap_corruption_excess_argc.phpt',
            'splheap_corruption_excess_argc.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
