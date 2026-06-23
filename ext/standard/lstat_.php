<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** lstat() — symlink-aware metadata via VmStatCache / libc lstat(2) (issue #1198, #7844). */
final class lstat_ extends Internal
{
    public function __construct()
    {
        parent::__construct('lstat');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('lstat() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'lstat', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $info = VmFs::statInfo($path, true);
        if (false === $info) {
            VmFilestatFailure::warnPathStatFailed($frame, 'lstat', $path, true);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($info);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('lstat() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'lstat', 0, 'filename');

        return JitStatArray::invoke($context, $path, true);
    }
}
