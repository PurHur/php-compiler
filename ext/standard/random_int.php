<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
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
        // php-src ext/random/random.c — ArgumentCountError (#28476).
        $this->requireExactArgCount($frame, 'random_int', 2);
        $min = self::parseBound($frame, 0, 1, 'min');
        $max = self::parseBound($frame, 1, 2, 'max');
        $result = VmRandom::randomInt($min, $max);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28476.
        if (!$this->requireExactJitArgCount($context, $args, 'random_int', 2)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        $min = JitRandomIntArg::lowerBound($context, $args[0], 1, 'min');
        $max = JitRandomIntArg::lowerBound($context, $args[1], 2, 'max');
        JitRandomInt::emitRuntimeRangeGuard($context, $min, $max);

        return JitRandomInt::call($context, $min, $max);
    }

    /**
     * Z_PARAM_LONG bound — coerce like php-src ext/random/random.c
     * (null→0 with E_DEPRECATED, enum→TypeError; #5795, #21754).
     *
     * @throws \TypeError when an enum case or non-coercible operand is passed
     */
    private static function parseBound(Frame $frame, int $argArrayIndex, int $argIndex, string $paramName): int
    {
        $var = $frame->calledArgs[$argArrayIndex]->resolveIndirect();
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

        return VmMath::parseZParamLongBuiltinArg(
            $var,
            'random_int',
            $argIndex,
            $paramName,
            $frame
        );
    }
}
