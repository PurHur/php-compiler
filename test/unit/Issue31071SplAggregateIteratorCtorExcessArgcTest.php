<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SPL aggregate iterator constructor excess argc (#31071).
 *
 * php-src: ext/spl/spl_iterators.c, ext/spl/spl_array.c
 */
final class Issue31071SplAggregateIteratorCtorExcessArgcTest extends TestCase
{
    public function testVmCtorArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_spl_aggregate_iterator_ctor_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_spl_aggregate_iterator_ctor_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'AppendIterator: AppendIterator::__construct() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'LimitIterator: LimitIterator::__construct() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'CachingIterator: CachingIterator::__construct() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'MultipleIterator: MultipleIterator::__construct() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'NoRewindIterator: NoRewindIterator::__construct() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'InfiniteIterator: InfiniteIterator::__construct() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArrayIterator: ArrayIterator::__construct() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'ArrayObject: ArrayObject::__construct() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringNotContainsString('ACCEPTED', $out);
    }
}
