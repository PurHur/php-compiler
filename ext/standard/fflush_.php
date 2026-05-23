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

/** fflush() — VM via VmFs; JIT/AOT via __compiler_fflush (issue #1189). */
final class fflush_ extends Internal
{
    public function __construct()
    {
        parent::__construct('fflush');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fflush() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('fflush() handle must be an integer in this compiler build');
        }
        $frame->returnVar->bool(VmFs::fflush($handleVar->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fflush() requires exactly one argument in this compiler build');
        }

        return JitFflush::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'fflush() handle'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
