<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class pcntl_fork extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_fork');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('pcntl_fork() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::processAvailable()) {
            throw new \Error('pcntl_fork() is not available in this compiler build');
        }
        $frame->returnVar->int(VmPcntl::fork());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_fork() is not implemented for JIT in this compiler build (issue #3327)');
    }
}
