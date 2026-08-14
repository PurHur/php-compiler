<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplDoublyLinkedList / SplQueue residual excess argc (#30964).
 *
 * php-src: ext/spl/spl_dllist.c
 */
final class Issue30964SplDllistExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30964_spl_dllist_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30964_spl_dllist_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'push: ArgumentCountError: SplDoublyLinkedList::push() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'unshift: ArgumentCountError: SplDoublyLinkedList::unshift() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'isEmpty: ArgumentCountError: SplDoublyLinkedList::isEmpty() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'bottom: ArgumentCountError: SplDoublyLinkedList::bottom() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'add: ArgumentCountError: SplDoublyLinkedList::add() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'setIteratorMode: ArgumentCountError: SplDoublyLinkedList::setIteratorMode() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'getIteratorMode: ArgumentCountError: SplDoublyLinkedList::getIteratorMode() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'offsetExists: ArgumentCountError: SplDoublyLinkedList::offsetExists() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'offsetGet: ArgumentCountError: SplDoublyLinkedList::offsetGet() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'offsetSet: ArgumentCountError: SplDoublyLinkedList::offsetSet() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'offsetUnset: ArgumentCountError: SplDoublyLinkedList::offsetUnset() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'enqueue: ArgumentCountError: SplQueue::enqueue() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'dequeue: ArgumentCountError: SplQueue::dequeue() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('push_ok: OK', $out);
        $this->assertStringContainsString('isEmpty_ok: OK', $out);
        $this->assertStringContainsString('bottom_ok: OK', $out);
        $this->assertStringContainsString('enqueue_ok: OK', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
