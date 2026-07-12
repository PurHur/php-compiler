<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class pcntl_signal_get_handler extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_signal_get_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'pcntl_signal_get_handler() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::available()) {
            throw new \Error('pcntl_signal_get_handler() is not available in this compiler build');
        }
        $signo = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_signal_get_handler', 0, 'signal');
        $frame->returnVar->copyFrom(VmPcntl::getHandler($signo));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_signal_get_handler() is not implemented for JIT in this compiler build (issue #6545)');
    }
}
