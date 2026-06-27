<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** filetype() — VM via lstat; JIT/AOT via libc lstat st_mode. */
final class filetype extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('filetype() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'filetype', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $type = VmFs::fileType($path);
        if (false === $type) {
            VmFilestatFailure::warnPathStatFailed($frame, 'filetype', $path, true);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($type);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('filetype() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lowerTypedString($context, $args[0], 'filetype', 0, 'filename');

        return JitFiletype::invoke($context, $path);
    }
}
