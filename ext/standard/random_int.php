<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg;
use PHPCompiler\JIT\Variable as JITVariable;
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

        // Compile-time null under strict_types: TypeError then stop IR (quotemeta #19117 pattern; #29779).
        // Continuing to lowerBound/rangeGuard after abort leaves a terminator mid-block under AOT.
        if ($context->callerStrictTypes) {
            if (self::isCompileTimeNull($args[0])) {
                InternalStrictArg::requireInt($context, $args[0], 'random_int', 'min', 1);

                return $context->getTypeFromString('int64')->constInt(0, false);
            }
            if (self::isCompileTimeNull($args[1])) {
                InternalStrictArg::requireInt($context, $args[1], 'random_int', 'max', 2);

                return $context->getTypeFromString('int64')->constInt(0, false);
            }
        }

        $min = JitRandomIntArg::lowerBound($context, $args[0], 1, 'min');
        $max = JitRandomIntArg::lowerBound($context, $args[1], 2, 'max');
        JitRandomInt::emitRuntimeRangeGuard($context, $min, $max);

        return JitRandomInt::call($context, $min, $max);
    }

    /**
     * Z_PARAM_LONG bound — coerce like php-src ext/random/random.c
     * (null→0 with E_DEPRECATED outside strict_types; TypeError under strict_types;
     * enum→TypeError; #5795, #21754, #29779).
     *
     * @throws \TypeError when an enum case or non-coercible / strict non-int operand is passed
     */
    private static function parseBound(Frame $frame, int $argArrayIndex, int $argIndex, string $paramName): int
    {
        // Prefer ForFrame so declare(strict_types=1) matches Zend (#29779 / sleep #19079).
        return VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            $argArrayIndex,
            'random_int',
            $argIndex,
            $paramName
        );
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }
}
