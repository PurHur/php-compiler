<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** rename() — VM via VmFs; JIT/AOT via libc rename(2). */
final class rename_ extends Internal
{
    public function __construct()
    {
        parent::__construct('rename');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('rename() requires exactly two arguments in this compiler build');
        }
        $from = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'rename', 0, 'from');
        $to = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'rename', 1, 'to');
        $ok = VmFs::rename($from, $to);
        if (!$ok) {
            VmFilestatFailure::warnRenameFailed($frame, $from, $to);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('rename() requires exactly two arguments in this compiler build');
        }
        $from = JitStringBuiltinArg::lower($context, $args[0], 'rename', 0, 'from');
        $to = JitStringBuiltinArg::lower($context, $args[1], 'rename', 1, 'to');

        return JitRename::invoke($context, $from, $to);
    }
}
