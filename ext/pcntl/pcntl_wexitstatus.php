<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

final class pcntl_wexitstatus extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_wexitstatus');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('pcntl_wexitstatus() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::processAvailable()) {
            throw new \Error('pcntl_wexitstatus() is not available in this compiler build');
        }
        $status = InternalStrictArg::requireInt($frame, 0, 'pcntl_wexitstatus', 'status')->toInt();
        $frame->returnVar->int(VmPcntl::wexitstatus($status));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_wexitstatus() is not implemented for JIT in this compiler build (issue #3327)');
    }
}
