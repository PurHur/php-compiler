<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * popen() — open pipe to a process (php-src ext/standard/exec.c; #6211 / #3278).
 *
 * VM: {@see VmFs::popen()}; JIT/AOT: __compiler_popen.
 */
final class popen extends Internal
{
    public function __construct()
    {
        parent::__construct('popen');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('popen() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'popen', 'command', 0);
        $command = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'popen',
            0,
            'command'
        );
        $mode = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'popen',
            1,
            'mode'
        );
        $handle = VmFs::popen($command, $mode);
        if (false === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('popen() requires exactly two arguments in this compiler build');
        }

        JitInternalStrictArg::rejectNullString($context, $args[0], 'popen', 'command', 1);

        return JitPopen::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'popen', 0, 'command'),
            JitStringBuiltinArg::lower($context, $args[1], 'popen', 1, 'mode')
        );
    }
}
