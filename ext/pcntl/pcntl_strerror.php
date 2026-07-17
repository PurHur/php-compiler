<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_strerror) — issue #20061. */
final class pcntl_strerror extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_strerror');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'pcntl_strerror() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $error = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_strerror', 0, 'error_code');
        $frame->returnVar->string(VmPcntl::strerror($error));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_strerror() is not implemented for JIT in this compiler build (issue #20061)');
    }
}
