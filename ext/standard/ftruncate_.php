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

/** ftruncate() — VM via VmFs; JIT/AOT via __compiler_ftruncate (issue #3256). */
final class ftruncate_ extends Internal
{
    public function __construct()
    {
        parent::__construct('ftruncate');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('ftruncate() requires exactly two arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $sizeVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('ftruncate() handle must be an integer in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $sizeVar->type) {
            throw new \LogicException('ftruncate() size must be an integer in this compiler build');
        }
        $frame->returnVar->bool(VmFs::ftruncate($handleVar->toInt(), $sizeVar->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('ftruncate() requires exactly two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'ftruncate() handle'),
            $i64
        );
        $size = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'ftruncate() size'),
            $i64
        );

        return JitFtruncate::invoke($context, $handle, $size);
    }
}
