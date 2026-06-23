<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stat() — file metadata array via VmStatCache / libc stat(2) (issue #1197, #7844). */
final class stat_ extends Internal
{
    public function __construct()
    {
        parent::__construct('stat');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('stat() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'stat', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $info = VmFs::statInfo($path, false);
        if (false === $info) {
            VmFilestatFailure::warnPathStatFailed($frame, 'stat', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($info);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stat() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'stat', 0, 'filename');

        return JitStatArray::invoke($context, $path, false);
    }
}
