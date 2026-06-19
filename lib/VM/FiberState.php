<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * VM state for PHP 8.1 fibers (issue #3130).
 *
 * Fibers run a user closure on an isolated stack until {@see FiberSupport::suspend()}
 * or normal completion; {@see Frame::$fiberSuspend} mirrors generator yield unwinding.
 */
final class FiberState
{
    public const STATUS_INIT = 0;

    public const STATUS_RUNNING = 1;

    public const STATUS_SUSPENDED = 2;

    public const STATUS_TERMINATED = 3;

    public int $status = self::STATUS_INIT;

    public ?Frame $frame = null;

    /** Value passed to {@see FiberSupport::suspend()} — returned from start()/resume(). */
    public Variable $suspendReturn;

    /** Value passed to start()/resume() — returned from suspend(). */
    public Variable $resumeArgument;

    /** Stack captured at {@see FiberSupport::suspend()} for getTrace() (#6470). */
    public Variable $suspendedTrace;

    /** Throwable passed to Fiber->throw() — thrown from suspend(). */
    public bool $hasPendingThrow = false;

    public Variable $pendingThrow;

    /** Callback return value after normal termination ({@see Fiber::getReturn()}, #5019). */
    public Variable $returnValue;

    public bool $hasReturnValue = false;

    /** Fiber callback ended with uncaught Throwable (#5019, Zend/zend_fibers.c). */
    public bool $threw = false;

    /**
     * Return target for the in-fiber `Fiber::suspend()` call.
     *
     * In Zend, suspend() returns only when the fiber is resumed; the resume argument is the return value.
     */
    public ?Variable $pendingSuspendReturnVar = null;

    /** Get/set hook frame suspended by Fiber::suspend() — resume on next hooked read (#9862). */
    public ?Frame $propertyHookSuspendFrame = null;

    /** Completed hook read waiting for the fiber callback property fetch (#9862). */
    public ?Variable $propertyHookResumeRead = null;

    public function __construct(
        public readonly ClosureState $callback,
        public readonly ObjectEntry $object,
    ) {
        $this->suspendReturn = new Variable();
        $this->resumeArgument = new Variable();
        $this->resumeArgument->null();
        $this->suspendedTrace = new Variable();
        $this->suspendedTrace->newArray();
        $this->pendingThrow = new Variable();
        $this->pendingThrow->null();
        $this->returnValue = new Variable();
        $this->returnValue->null();
    }
}
