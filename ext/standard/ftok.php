<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** ftok() — System V IPC key from path (VmFtok libc ftok(3), #6296). */
final class ftok extends Internal
{
    public function __construct()
    {
        parent::__construct('ftok');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError('ftok() expects exactly 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'ftok', 0, 'filename');
        if ('' === $pathname) {
            throw new \ValueError(VmString::emptyStringArgValueErrorMessageCannot('ftok', 0, 'filename'));
        }
        $proj = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftok', 1, 'project_id');
        if (1 !== \strlen($proj)) {
            throw new \ValueError('ftok(): Argument #2 ($project_id) must be a single character');
        }
        $key = VmFtok::invoke($pathname, \ord($proj[0]));
        if (-1 === $key) {
            $this->triggerWarning($frame, VmFtok::lastErrorMessage());
        }
        $frame->returnVar->int($key);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError('ftok() expects exactly 2 arguments, '.$argc.' given');
        }

        return JitFtok::invoke($context, $args[0], $args[1]);
    }

    private function triggerWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
