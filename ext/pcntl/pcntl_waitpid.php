<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class pcntl_waitpid extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_waitpid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'pcntl_waitpid() expects at least 2 arguments and at most 3, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::processAvailable()) {
            throw new \Error('pcntl_waitpid() is not available in this compiler build');
        }
        $pid = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_waitpid', 0, 'process_id');
        $options = 0;
        if ($argc >= 3) {
            $options = VmPcntlArg::coerceIntArg($frame->calledArgs[2], 'pcntl_waitpid', 2, 'options');
        }
        $status = 0;
        $waitRc = VmPcntl::waitpid($pid, $status, $options);
        // ZEND_SEND_REF — write through byRefTarget so caller $status updates (#19564)
        $frame->calledArgs[1]->byRefTarget()->int($status);
        $frame->returnVar->int($waitRc);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_waitpid() is not implemented for JIT in this compiler build (issue #3327)');
    }
}
