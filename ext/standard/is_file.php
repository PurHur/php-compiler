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

/** is_file() — VM via VmStatPath; JIT via libc stat (issue #194, #8186). */
final class is_file extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_file() requires exactly one argument');
        }
        $path = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'is_file', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmStatPath::isFile($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('is_file() requires exactly one argument');
        }
        $path = JitStringBuiltinArg::lowerTypedString($context, $args[0], 'is_file', 0, 'filename');

        return JitStat::pathIsFile($context, $path);
    }
}
