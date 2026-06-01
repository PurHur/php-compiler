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

/** readdir() — VM via VmDir; JIT/AOT via __compiler_readdir (issue #3235). */
final class readdir extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('readdir() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('readdir() handle must be an integer in this compiler build');
        }
        $entry = VmDir::readdir($handleVar->toInt());
        if (false === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($entry);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('readdir() requires exactly one argument in this compiler build');
        }
        \PHPCompiler\JIT\Builtin\StringDir::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');

        return JitReaddir::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'readdir() handle'),
                $i64
            )
        );
    }
}
