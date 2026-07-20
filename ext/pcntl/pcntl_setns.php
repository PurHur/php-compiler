<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_setns) — issue #21257. */
final class pcntl_setns extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_setns');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'pcntl_setns() expects at most 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pid = null;
        if ($argc >= 1) {
            $pidArg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pidArg->type) {
                $pid = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_setns', 0, 'process_id');
            }
        }
        $nstype = PcntlConstants::CLONE_NEWNET;
        if ($argc >= 2) {
            $nstype = VmPcntlArg::coerceIntArg($frame->calledArgs[1], 'pcntl_setns', 1, 'nstype');
        }
        $frame->returnVar->bool(VmPcntl::setns($pid, $nstype));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_setns() is not implemented for JIT in this compiler build (issue #21257)');
    }
}
