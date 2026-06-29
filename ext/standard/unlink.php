<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** unlink() — VM via VmFs; JIT/AOT via libc unlink(2). */
final class unlink extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'unlink() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'unlink() expects at most 2 arguments, '.$argc.' given'
            );
        }
        if (isset($frame->calledArgs[1])) {
            VmStreamContext::validateOptionalContextArg($frame->calledArgs[1], 'unlink', 2);
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'unlink');
        $ok = VmFs::unlink($path);
        if (!$ok) {
            VmFilestatFailure::warnUnlinkFailed($frame, $path);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'unlink() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'unlink() expects at most 2 arguments, '.$argc.' given'
            );
        }
        if (isset($args[1])) {
            JitStreamContextOptionalArg::validate($context, $args[1], 'unlink', 2);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'unlink');

        return JitUnlink::invoke($context, $path);
    }
}
