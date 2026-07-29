<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

final class pcntl_signal extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_signal');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'pcntl_signal() expects at least 2 arguments and at most 3, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::available()) {
            $frame->returnVar->bool(false);

            return;
        }
        $signo = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_signal', 0, 'signal');
        $handlerArg = $frame->calledArgs[1];
        $handler = null;
        if (Variable::TYPE_NULL !== $handlerArg->resolveIndirect()->type) {
            $handler = $handlerArg;
            // php-src: callable|int — SIG_DFL / SIG_IGN are int dispositions, not callables (#24551)
            $resolved = $handlerArg->resolveIndirect();
            $isDisposition = Variable::TYPE_INTEGER === $resolved->type
                && (PcntlConstants::SIG_DFL === $resolved->toInt()
                    || PcntlConstants::SIG_IGN === $resolved->toInt());
            if (!$isDisposition) {
                VmPcntlArg::requireCallable($frame->vmContext, $handler, 'pcntl_signal', 1);
            }
        }
        $frame->returnVar->bool(VmPcntl::signal($signo, $handler));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_signal() is not implemented for JIT in this compiler build (issue #6680)');
    }
}
