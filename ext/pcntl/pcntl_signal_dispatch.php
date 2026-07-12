<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class pcntl_signal_dispatch extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_signal_dispatch');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('pcntl_signal_dispatch() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmPcntl::dispatch($frame->vmContext));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_signal_dispatch() is not implemented for JIT in this compiler build (issue #6680)');
    }
}
