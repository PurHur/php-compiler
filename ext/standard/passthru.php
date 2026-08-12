<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * passthru() — run external program and print raw output (php-src ext/standard/exec.c; #3278).
 *
 * VM: {@see VmExecNative}; JIT/AOT: {@see JitExec} / ProcessRuntime (#8640, phase 2 #3278).
 */
final class passthru extends Internal
{
    public function __construct()
    {
        parent::__construct('passthru');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('passthru() accepts one or two arguments in this compiler build');
        }
        $command = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'passthru', 'command', false);
        // php-src exec.c — Zend "cannot be empty" (#30340)
        VmString::rejectEmptyBuiltinStringArg($command, 'passthru', 0, 'command', true);
        $result = VmExecNative::run($command);
        if (false !== $result) {
            if (!VmExecNative::linesToStdout($result['lines'])) {
                $result = false;
            } elseif ($argc >= 2) {
                $frame->calledArgs[1]->resolveIndirect()->int($result['status']);
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->null();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitExec::passthru($context, ...$args);
    }
}
