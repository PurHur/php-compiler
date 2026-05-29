<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fnmatch() — POSIX glob pattern match (VM via host; JIT/AOT via libc fnmatch, issue #3189). */
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
        $patternVar = $frame->calledArgs[0]->resolveIndirect();
        $stringVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $patternVar->type || Variable::TYPE_STRING !== $stringVar->type) {
            throw new \LogicException('fnmatch() pattern and string must be strings in this compiler build');
        }
        $flags = 0;
        if (3 === $argc) {
            $flagsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('fnmatch() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        $frame->returnVar->bool(VmFnmatch::match(
            $patternVar->toString(),
            $stringVar->toString(),
            $flags
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('fnmatch() requires two or three arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('fnmatch() pattern and string must be strings in this compiler build');
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
            $this->jitString($context, $args[0], 'fnmatch() argument #1'),
            $this->jitString($context, $args[1], 'fnmatch() argument #2'),
            $flags
        );
    }
}
