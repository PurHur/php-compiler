<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: SplHeap / SplPriorityQueue excess argc (#30955).
 *
 * @group llvm
 */
final class SplHeapExcessArgc30955JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splheap_excess_argc_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splheap_excess_argc.phpt',
            'splheap_excess_argc_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
