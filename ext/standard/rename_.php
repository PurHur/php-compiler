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
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'rename() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'rename() expects at most 3 arguments, '.$argc.' given'
            );
        }
        if (isset($frame->calledArgs[2])) {
            VmStreamContext::validateOptionalContextArg($frame->calledArgs[2], 'rename', 3);
        }
        $from = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'rename', 0, 'from');
        $to = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[1], 'rename', 1, 'to');
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
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'rename() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'rename() expects at most 3 arguments, '.$argc.' given'
            );
        }
        if (isset($args[2])) {
            JitStreamContextOptionalArg::validate($context, $args[2], 'rename', 3);
        }
        $from = JitStringBuiltinArg::lowerTypedString($context, $args[0], 'rename', 0, 'from');
        $to = JitStringBuiltinArg::lowerTypedString($context, $args[1], 'rename', 1, 'to');

        return JitRename::invoke($context, $from, $to);
    }
}
