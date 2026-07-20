<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_sigwaitinfo) — issue #21330. */
final class pcntl_sigwaitinfo extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_sigwaitinfo');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'pcntl_sigwaitinfo() expects at least 1 argument and at most 2, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::available()) {
            throw new \Error('pcntl_sigwaitinfo() is not available in this compiler build');
        }
        $signals = VmPcntlArg::parseSignalList($frame->calledArgs[0], 'pcntl_sigwaitinfo', 0, 'signals');
        $infoOut = $argc >= 2 ? $frame->calledArgs[1] : null;
        $rc = VmPcntl::sigwaitinfo($signals, $infoOut);
        if (false === $rc) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($rc);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_sigwaitinfo() is not implemented for JIT in this compiler build (issue #21330)');
    }
}
