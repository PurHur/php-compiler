<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** pcntl_errno() — alias of pcntl_get_last_error() (php-src ext/pcntl/pcntl.c; #21330). */
final class pcntl_errno extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_errno');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError('pcntl_errno() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmPcntl::getLastError());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_errno() is not implemented for JIT in this compiler build (issue #21330)');
    }
}
