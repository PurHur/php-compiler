<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** rmdir() — VM via VmFs; JIT/AOT via libc rmdir(2). */
final class rmdir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('rmdir');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('rmdir() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'rmdir', 0, 'directory');
        $ok = VmFs::rmdir($path);
        if (!$ok) {
            if (VmStatPath::isDir($path) && VmFs::isDirNonempty($path)) {
                VmFilestatFailure::warnRmdirNotEmpty($frame, $path);
            } else {
                VmFilestatFailure::warnRmdirMissing($frame, $path);
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('rmdir() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'rmdir', 0, 'directory');

        return JitRmdir::invoke($context, $path);
    }
}
