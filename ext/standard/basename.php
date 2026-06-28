<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** basename() for path strings (subset of PHP; JIT/AOT via JitPath). */
final class basename extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('basename() expects 1 or 2 arguments');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'basename', 0, 'path');
        if (null === $frame->returnVar) {
            return;
        }
        $suffix = '';
        if (2 === $argc) {
            $suffix = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[1], 'basename', 1, 'suffix');
        }
        $frame->returnVar->string(VmString::basename($path, $suffix));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('basename() expects 1 or 2 arguments');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'basename', 0, 'path');
        $base = JitPath::basename($context, $path);
        if (2 === $argc) {
            $suffix = JitStringBuiltinArg::lower($context, $args[1], 'basename', 1, 'suffix');

            return JitPath::stripSuffixIfPresent($context, $base, $suffix);
        }

        return $base;
    }
}
