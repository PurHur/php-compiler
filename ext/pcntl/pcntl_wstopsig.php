<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_wstopsig) — issue #19565. */
final class pcntl_wstopsig extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_wstopsig');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('pcntl_wstopsig() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::processAvailable()) {
            throw new \Error('pcntl_wstopsig() is not available in this compiler build');
        }
        $status = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_wstopsig', 0, 'status');
        $frame->returnVar->int(VmPcntl::wstopsig($status));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_wstopsig() is not implemented for JIT in this compiler build (issue #19565)');
    }
}
