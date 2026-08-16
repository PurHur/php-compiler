<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: SplPriorityQueue valid()/key() after insert without rewind (#31601).
 */
final class SplPriorityQueueValidAfterInsert31601VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splpriorityqueue_valid_after_insert.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splpriorityqueue_valid_after_insert.phpt',
            'splpriorityqueue_valid_after_insert.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
