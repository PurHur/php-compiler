<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Fiber method excess argc → ArgumentCountError (#30906).
 *
 * php-src: Zend/zend_fibers.c zim_Fiber_* / zend_fibers.stub.php
 */
final class Issue30906FiberExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30906_fiber_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30906_fiber_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: Fiber::getCurrent() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Fiber::resume() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Fiber::getReturn() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Fiber::isRunning() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Fiber::isTerminated() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Fiber::isSuspended() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Fiber::isStarted() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Fiber::throw() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString('getCurrent_ok: NULL', $out);
        $this->assertStringContainsString('resume_ok: NULL', $out);
        $this->assertStringContainsString('getReturn_ok: 1', $out);
        $this->assertStringContainsString('isTerminated_ok: true', $out);
        $this->assertStringContainsString('throw_ok: Exception: e', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertDoesNotMatchRegularExpression('/^getCurrent: NULL/m', $out);
        $this->assertDoesNotMatchRegularExpression('/^resume_extra: NULL/m', $out);
    }
}
