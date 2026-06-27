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

/** symlink() — VM via VmFs; JIT/AOT via libc symlinkat(2) (issue #3227). */
final class symlink_ extends Internal
{
    public function __construct()
    {
        parent::__construct('symlink');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('symlink() requires exactly two arguments in this compiler build');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'symlink', 'target', 0, $frame);
        InternalStrictArg::rejectNullString($frame->calledArgs[1], 'symlink', 'link', 1, $frame);
        $target = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'symlink', 0, 'target');
        $linkPath = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'symlink', 1, 'link');
        $ok = VmFs::symlink($target, $linkPath);
        if (!$ok) {
            VmFilestatFailure::warnNoSuchFile($frame, 'symlink');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('symlink() requires exactly two arguments in this compiler build');
        }
        $target = JitStringBuiltinArg::lower($context, $args[0], 'symlink', 0, 'target');
        $linkPath = JitStringBuiltinArg::lower($context, $args[1], 'symlink', 1, 'link');

        return JitSymlink::invoke($context, $target, $linkPath);
    }
}
