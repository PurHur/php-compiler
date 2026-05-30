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

    public function __construct(
        public readonly ClosureState $callback,
        public readonly ObjectEntry $object,
    ) {
        $this->suspendReturn = new Variable();
        $this->resumeArgument = new Variable();
        $this->resumeArgument->null();
    }
}
