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
 * system() — run external program and print output; return last line (php-src ext/standard/exec.c; #3278).
 *
 * VM: {@see VmExecNative}; JIT/AOT: {@see JitExec} / ProcessRuntime (#8640, phase 2 #3278).
 */
final class system extends Internal
{
    public function __construct()
    {
        parent::__construct('system');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('system() accepts one or two arguments in this compiler build');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'system', 'command', 0);
        $command = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'system', 0, 'command');
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
        $lines = $result['lines'];
        if ([] === $lines) {
            $frame->returnVar->string('');

            return;
        }
        $frame->returnVar->string($lines[\count($lines) - 1]);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitExec::system($context, ...$args);
    }
}
