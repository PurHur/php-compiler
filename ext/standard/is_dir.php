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

/** is_dir() — VM via VmStatPath; JIT via libc stat (issue #194, #8186). */
final class is_dir extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_dir() requires exactly one argument');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'is_dir', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmStatPath::isDir($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('is_dir() requires exactly one argument');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'is_dir', 0, 'filename');

        return JitStat::pathIsDir($context, $path);
    }
}
