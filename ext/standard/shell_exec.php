<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
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
        // php-src ext/standard/exec.c / basic_functions.stub.php — ArgumentCountError (#30566)
        $this->requireExactArgCount($frame, 'shell_exec', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $command = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'shell_exec', 'command', false);
        // php-src exec.c — Zend "cannot be empty" (#30340)
        VmString::rejectEmptyBuiltinStringArg($command, 'shell_exec', 0, 'command', true);
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
        // Catchable ArgumentCountError (AOT) — #30566.
        if (!$this->requireExactJitArgCount($context, $args, 'shell_exec', 1)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        $command = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'shell_exec', 0, 'command', 'string', null, false);
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $command,
            VmString::emptyStringArgValueErrorMessageCannot('shell_exec', 0, 'command')
        );

        return JitShellExec::invoke($context, $command);
    }
}
