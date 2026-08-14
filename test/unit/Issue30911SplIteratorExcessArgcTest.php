<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ArrayIterator / SplStack iterator excess argc (#30911).
 *
 * php-src: ext/spl/spl_array.c / spl_dllist.c
 */
final class Issue30911SplIteratorExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30911_spl_iterator_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30911_spl_iterator_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: ArrayIterator::current() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ArrayIterator::serialize() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: SplDoublyLinkedList::top() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: SplDoublyLinkedList::pop() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: SplDoublyLinkedList::count() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok_current: OK 1', $out);
        $this->assertStringContainsString('ok_top: OK 1', $out);
        $this->assertDoesNotMatchRegularExpression('/^current: OK /m', $out);
        $this->assertDoesNotMatchRegularExpression('/^top: OK /m', $out);
        $this->assertDoesNotMatchRegularExpression('/^pop: OK /m', $out);
    }
}
