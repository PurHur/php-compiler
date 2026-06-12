<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** readdir() — VM via VmDir; JIT/AOT via __compiler_readdir (issue #3235). */
final class readdir extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('readdir() requires exactly one argument in this compiler build');
        }
        $handle = VmDirArg::requireDirHandle($frame->calledArgs[0], 'readdir');
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmDir::readdir($handle);
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
