<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: SplHeap family valid()/key() after insert without rewind (#31600).
 */
final class SplHeapValidAfterInsert31600VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splheap_valid_after_insert.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splheap_valid_after_insert.phpt',
            'splheap_valid_after_insert.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
