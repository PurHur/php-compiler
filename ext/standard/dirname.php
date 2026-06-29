<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** dirname() for path strings (subset of PHP; JIT/AOT via JitPath). */
final class dirname extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('dirname() expects 1 or 2 arguments');
        }
        $path = VmString::stringBuiltinArgForFrame($frame, 0, 'dirname', 0, 'path');
        if (null === $frame->returnVar) {
            return;
        }
        $levels = 1;
        if (2 === $argc) {
            $levels = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'dirname',
                2,
                'levels'
            );
        }
        $frame->returnVar->string(VmString::dirname($path, $levels));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('dirname() expects 1 or 2 arguments');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'dirname', 0, 'path');
        if (1 === $argc) {
            return JitPath::dirname($context, $path);
        }
        $levels = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'dirname', 2, 'levels');

        return JitPath::dirnameWithLevels($context, $path, $levels);
    }
}
