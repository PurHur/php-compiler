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

/**
 * random_int() — CSPRNG integer in [min, max] (issue #2330).
 *
 * VM: {@see VmRandom::randomInt()}; JIT/AOT: {@see JitRandomInt}.
 */
final class random_int extends Internal
{
    public function __construct()
    {
        parent::__construct('random_int');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('random_int() requires exactly two arguments');
        }
        $minArg = $frame->calledArgs[0]->resolveIndirect();
        $maxArg = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $minArg->type || Variable::TYPE_INTEGER !== $maxArg->type) {
            throw new \LogicException('random_int() only supports integers in this compiler build');
        }
        $frame->returnVar->int(VmRandom::randomInt($minArg->toInt(), $maxArg->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('random_int() requires exactly two arguments');
        }

        return JitRandomInt::call(
            $context,
            JitLongArg::lower($context, $args[0], 'random_int() min'),
            JitLongArg::lower($context, $args[1], 'random_int() max')
        );
    }
}
