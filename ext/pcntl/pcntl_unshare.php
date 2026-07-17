<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_unshare) — issue #20061. */
final class pcntl_unshare extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_unshare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'pcntl_unshare() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $flags = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_unshare', 0, 'flags');
        $frame->returnVar->bool(VmPcntl::unshare($flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_unshare() is not implemented for JIT in this compiler build (issue #20061)');
    }
}
