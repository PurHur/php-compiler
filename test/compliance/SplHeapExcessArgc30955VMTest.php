<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SplHeap / SplPriorityQueue excess argc (#30955). */
final class SplHeapExcessArgc30955VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splheap_excess_argc.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splheap_excess_argc.phpt',
            'splheap_excess_argc.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
