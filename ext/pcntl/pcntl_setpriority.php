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
 * php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_setpriority) — issue #20046.
 */
final class pcntl_setpriority extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_setpriority');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'pcntl_setpriority() expects at least 1 argument and at most 3, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::priorityAvailable()) {
            throw new \Error('pcntl_setpriority() is not available in this compiler build');
        }

        $priority = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_setpriority', 0, 'priority');
        $pid = null;
        $who = PcntlConstants::PRIO_PROCESS;
        if ($argc >= 2) {
            $pidArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pidArg->type) {
                $pid = VmPcntlArg::coerceIntArg($frame->calledArgs[1], 'pcntl_setpriority', 1, 'process_id');
            }
        }
        if ($argc >= 3) {
            $who = VmPcntlArg::coerceIntArg($frame->calledArgs[2], 'pcntl_setpriority', 2, 'mode');
        }

        $frame->returnVar->bool(VmPcntl::setpriority($priority, $pid, $who));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_setpriority() is not implemented for JIT in this compiler build (issue #20046)');
    }
}
