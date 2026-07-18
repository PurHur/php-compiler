<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_getcpu) — #20510. */
final class pcntl_getcpu extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_getcpu');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                'pcntl_getcpu() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmPcntl::getcpu());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_getcpu() is not implemented for JIT in this compiler build (issue #20510)');
    }
}
