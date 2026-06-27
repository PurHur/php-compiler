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

/** chdir() — VM via VmChdirNative (libc); JIT/AOT via JitChdir (#8180). */
final class chdir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chdir');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('chdir() requires exactly one argument in this compiler build');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'chdir', 'directory', 0, $frame);
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'chdir', 0, 'directory');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::chdir($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('chdir() requires exactly one argument in this compiler build');
        }
        return JitChdir::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'chdir', 0, 'directory')
        );
    }
}
