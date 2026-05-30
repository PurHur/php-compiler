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

/** flock() — VM via VmFs; JIT/AOT via __compiler_flock (issue #3141, php-src ext/standard/flock.c). */
final class flock extends Internal
{
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('flock() requires at least two arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $operationVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type || Variable::TYPE_INTEGER !== $operationVar->type) {
            throw new \LogicException('flock() handle and operation must be integers in this compiler build');
        }
        $frame->returnVar->bool(VmFs::flock($handleVar->toInt(), $operationVar->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('flock() requires at least two arguments in this compiler build');
        }

        return JitFlock::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'flock() handle'),
                $context->getTypeFromString('int64')
            ),
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'flock() operation'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
