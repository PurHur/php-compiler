<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_wifcontinued) — issue #21330. */
final class pcntl_wifcontinued extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_wifcontinued');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('pcntl_wifcontinued() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::processAvailable()) {
            throw new \Error('pcntl_wifcontinued() is not available in this compiler build');
        }
        $status = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_wifcontinued', 0, 'status');
        $frame->returnVar->bool(VmPcntl::wifcontinued($status));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_wifcontinued() is not implemented for JIT in this compiler build (issue #21330)');
    }
}
