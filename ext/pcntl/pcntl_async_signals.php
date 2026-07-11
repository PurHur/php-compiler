<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

final class pcntl_async_signals extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_async_signals');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'pcntl_async_signals() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $enable = null;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $enable = $arg->toBool();
            }
        }
        $frame->returnVar->bool(VmPcntl::asyncSignals($enable));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_async_signals() is not implemented for JIT in this compiler build (issue #6545)');
    }
}
