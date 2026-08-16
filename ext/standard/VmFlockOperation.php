<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * flock() $operation parsing — php-src ext/standard/file.c PHP_FUNCTION(flock) (#16575, #31462).
 *
 * Z_PARAM_LONG: soft-null → E_DEPRECATED + coerce to 0, then LOCK_* ValueError; strict_types → TypeError.
 */
final class VmFlockOperation
{
    public const VALUE_ERROR_MSG = 'flock(): Argument #2 ($operation) must be one of LOCK_SH, LOCK_EX, or LOCK_UN';

    public const TYPE_ERROR_NULL_MSG = 'flock(): Argument #2 ($operation) must be of type int, null given';

    private const LOCK_NB = 4;

    public static function parseOperationForFrame(Frame $frame, int $argIndex = 1): int
    {
        // Z_PARAM_LONG — Argument #2 ($operation); soft-null DEP+0 then ValueError (#31462).
        $operation = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            $argIndex,
            'flock',
            2,
            'operation'
        );
        self::assertValidOperation($operation);

        return $operation;
    }

    /** @deprecated Prefer {@see parseOperationForFrame} so soft-null DEP sees the caller frame (#31462). */
    public static function parseOperation(Variable $var, ?Frame $frame = null): int
    {
        if (null !== $frame) {
            return self::parseOperationForFrame($frame);
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            // No frame: still emit DEP then ValueError like Z_PARAM_LONG (#31462).
            VmNullNumberParamDeprecation::emit(null, 'flock', 2, 'operation', 'int');
            throw new \ValueError(self::VALUE_ERROR_MSG);
        }

        $operation = VmMath::parseIntBuiltinArg($var, 'flock', 2, 'operation');
        self::assertValidOperation($operation);

        return $operation;
    }

    public static function assertValidOperation(int $operation): void
    {
        $base = $operation & ~self::LOCK_NB;
        if ($base < 1 || $base > 3) {
            throw new \ValueError(self::VALUE_ERROR_MSG);
        }
    }
}
