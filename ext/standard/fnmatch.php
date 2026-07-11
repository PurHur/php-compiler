<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fnmatch() — POSIX glob pattern match (VM via VmFnmatchPure; JIT/AOT via JitFnmatch, #3189/#7756/#12075). */
final class fnmatch extends Internal
{
    public function __construct()
    {
        parent::__construct('fnmatch');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('fnmatch() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pattern = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'fnmatch', 0, 'pattern');
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'fnmatch', 1, 'filename');
        $flags = 0;
        if (3 === $argc) {
            $flagsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('fnmatch() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        $frame->returnVar->bool(VmFnmatch::match($pattern, $filename, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('fnmatch() requires two or three arguments in this compiler build');
        }
        $i32 = $context->getTypeFromString('int32');
        $flags = $i32->constInt(0, false);
        if (3 === $argc) {
            $flags = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'fnmatch() flags'),
                $i32
            );
        }

        return JitFnmatch::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'fnmatch', 0, 'pattern'),
            JitStringBuiltinArg::lower($context, $args[1], 'fnmatch', 1, 'filename'),
            $flags
        );
    }
}
