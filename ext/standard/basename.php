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

/** basename() for path strings (subset of PHP; JIT/AOT via JitPath). */
final class basename extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('basename() expects 1 or 2 arguments');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('basename() only supports strings in this compiler build');
        }
        $suffix = '';
        if (2 === $argc) {
            $s = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $s->type) {
                throw new \LogicException('basename() only supports strings in this compiler build');
            }
            $suffix = $s->toString();
        }
        $frame->returnVar->string(VmString::basename($v->toString(), $suffix));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('basename() expects 1 or 2 arguments');
        }
        $path = JitStringArg::lower($context, $args[0], 'basename() path');
        $base = JitPath::basename($context, $path);
        if (2 === $argc) {
            $suffix = JitStringArg::lower($context, $args[1], 'basename() suffix');

            return JitPath::stripSuffixIfPresent($context, $base, $suffix);
        }

        return $base;
    }
}
