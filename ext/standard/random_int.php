<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
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
        $min = self::parseBound($frame->calledArgs[0]->resolveIndirect(), 1, 'min');
        $max = self::parseBound($frame->calledArgs[1]->resolveIndirect(), 2, 'max');
        $result = VmRandom::randomInt($min, $max);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('random_int() requires exactly two arguments');
        }

        $min = JitRandomIntArg::lowerBound($context, $args[0], 1, 'min');
        $max = JitRandomIntArg::lowerBound($context, $args[1], 2, 'max');
        JitRandomInt::emitRuntimeRangeGuard($context, $min, $max);

        return JitRandomInt::call($context, $min, $max);
    }

    /**
     * Z_PARAM_LONG bound — reject enum cases before int-only check (#5795, ext/standard/random.c).
     *
     * @throws \TypeError when an enum case operand is passed (php-src-strict)
     */
    private static function parseBound(Variable $var, int $argIndex, string $paramName): int
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
            $given = null !== $enumClass ? $enumClass->name : 'object';
            throw new \TypeError(sprintf(
                'random_int(): Argument #%d ($%s) must be of type int, %s given',
                $argIndex,
                $paramName,
                $given
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException('random_int() only supports integers in this compiler build');
        }

        return $var->toInt();
    }
}
