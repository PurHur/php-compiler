<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tempnam() — VM via VmFs; JIT/AOT via TempnamJitHelper PHP (#15685). */
final class tempnam extends Internal
{
    public function __construct()
    {
        parent::__construct('tempnam');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('tempnam() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $dir = VmFsTempnam::resolveDirectoryArg($frame->calledArgs[0], $frame);
        $prefix = VmString::coercePathBuiltinArg($frame->calledArgs[1], 'tempnam', 1, 'prefix');
        $path = VmFsTempnam::invoke($dir, $prefix, $frame);
        if (false === $path) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($path);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('tempnam() requires exactly two arguments in this compiler build');
        }

        return JitTempnam::invoke(
            $context,
            JitTempnam::lowerDirectory($context, $args[0]),
            JitStringBuiltinArg::lowerPath($context, $args[1], 'tempnam', 1, 'prefix')
        );
    }
}
