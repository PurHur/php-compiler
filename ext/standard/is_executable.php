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

/** is_executable() — VM via VmStatPath; JIT via stat mode access (#8186, #8990). */
final class is_executable extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_executable() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'is_executable', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmStatPath::isExecutable($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('is_executable() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lowerTypedString($context, $args[0], 'is_executable', 0, 'filename');

        return JitStat::pathIsExecutable($context, $path);
    }
}
