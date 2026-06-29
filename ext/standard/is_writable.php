<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** is_writable() — VM via VmStatPath; JIT via stat mode access (#8186, #8990). */
final class is_writable extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_writable() requires exactly one argument in this compiler build');
        }
        $path = VmString::stringBuiltinArgForFrame($frame, 0, 'is_writable', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmStatPath::isWritable($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('is_writable() requires exactly one argument in this compiler build');
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'is_writable');

        return JitStat::pathIsWritable($context, $path);
    }
}
