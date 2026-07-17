<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_getpriority) — issue #20046.
 */
final class pcntl_getpriority extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_getpriority');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'pcntl_getpriority() expects at most 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::priorityAvailable()) {
            throw new \Error('pcntl_getpriority() is not available in this compiler build');
        }

        $pid = null;
        $who = PcntlConstants::PRIO_PROCESS;
        if ($argc >= 1) {
            $pidArg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pidArg->type) {
                $pid = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_getpriority', 0, 'process_id');
            }
        }
        if ($argc >= 2) {
            $who = VmPcntlArg::coerceIntArg($frame->calledArgs[1], 'pcntl_getpriority', 1, 'mode');
        }

        $result = VmPcntl::getpriority($pid, $who);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_getpriority() is not implemented for JIT in this compiler build (issue #20046)');
    }
}
