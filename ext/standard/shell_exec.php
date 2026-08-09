<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** shell_exec() — VM via VmShellExecNative libc popen; JIT/AOT via __compiler_shell_exec (#8250). */
final class shell_exec extends Internal
{
    public function __construct()
    {
        parent::__construct('shell_exec');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('shell_exec() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $command = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'shell_exec', 'command', false);
        VmString::rejectEmptyBuiltinStringArg($command, 'shell_exec', 0, 'command');
        $result = VmShellExecNative::shellExec($command);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } elseif (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('shell_exec() requires exactly one argument');
        }

        $command = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'shell_exec', 0, 'command', 'string', null, false);
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $command,
            'shell_exec(): Argument #1 ($command) must not be empty'
        );

        return JitShellExec::invoke($context, $command);
    }
}
