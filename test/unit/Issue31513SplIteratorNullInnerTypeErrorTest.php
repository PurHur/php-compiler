<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Outer-iterator null TypeError cites concrete method + Iterator (#31513).
 *
 * php-src: ext/spl/spl_iterators.c / spl_iterators.stub.php
 */
final class Issue31513SplIteratorNullInnerTypeErrorTest extends TestCase
{
    public function testVmTypeErrorMessagesMatchZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_spl_iterator_null_inner_message.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_spl_iterator_null_inner_message.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'append: TypeError: AppendIterator::append(): Argument #1 ($iterator) must be of type Iterator, null given',
            $out
        );
        $this->assertStringContainsString(
            'limit: TypeError: LimitIterator::__construct(): Argument #1 ($iterator) must be of type Iterator, null given',
            $out
        );
        $this->assertStringContainsString(
            'caching: TypeError: CachingIterator::__construct(): Argument #1 ($iterator) must be of type Iterator, null given',
            $out
        );
        $this->assertStringContainsString(
            'norewind: TypeError: NoRewindIterator::__construct(): Argument #1 ($iterator) must be of type Iterator, null given',
            $out
        );
        $this->assertStringContainsString(
            'infinite: TypeError: InfiniteIterator::__construct(): Argument #1 ($iterator) must be of type Iterator, null given',
            $out
        );
        $this->assertStringContainsString(
            'multi: TypeError: MultipleIterator::attachIterator(): Argument #1 ($iterator) must be of type Iterator, null given',
            $out
        );
        $this->assertStringContainsString(
            'rii: TypeError: RecursiveIteratorIterator::__construct(): Argument #1 ($iterator) must be of type object, null given',
            $out
        );
        $this->assertStringContainsString(
            'ii: TypeError: IteratorIterator::__construct(): Argument #1 ($iterator) must be of type Traversable, null given',
            $out
        );
        $this->assertStringNotContainsString(
            'append: TypeError: IteratorIterator::__construct()',
            $out
        );
        $this->assertStringNotContainsString(
            'limit: TypeError: IteratorIterator::__construct()',
            $out
        );
        $this->assertStringNotContainsString(
            'caching: TypeError: IteratorIterator::__construct()',
            $out
        );
    }
}
