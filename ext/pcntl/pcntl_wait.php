<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_wait) — issue #19565. */
final class pcntl_wait extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_wait');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'pcntl_wait() expects at least 1 argument and at most 2, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::processAvailable()) {
            throw new \Error('pcntl_wait() is not available in this compiler build');
        }
        $options = 0;
        if ($argc >= 2) {
            $options = VmPcntlArg::coerceIntArg($frame->calledArgs[1], 'pcntl_wait', 1, 'flags');
        }
        $status = 0;
        $waitRc = VmPcntl::wait($status, $options);
        $frame->calledArgs[0]->byRefTarget()->int($status);
        $frame->returnVar->int($waitRc);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_wait() is not implemented for JIT in this compiler build (issue #19565)');
    }
}
