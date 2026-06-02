<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
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
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('dirname() only supports strings in this compiler build');
        }
        $levels = 1;
        if (2 === $argc) {
            $levels = $frame->calledArgs[1]->resolveIndirect()->toInt();
        }
        $frame->returnVar->string(VmString::dirname($v->toString(), $levels));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('dirname() expects 1 or 2 arguments');
        }
        $path = JitStringArg::lower($context, $args[0], 'dirname() path');
        if (1 === $argc) {
            return JitPath::dirname($context, $path);
        }
        $levels = JitDirname::coerceLevels($context, $args[1]);

        return JitPath::dirnameWithLevels($context, $path, $levels);
    }
}
