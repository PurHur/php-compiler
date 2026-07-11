<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/** flock() $operation parsing — php-src ext/standard/flock.c (#16575). */
final class VmFlockOperation
{
    public const VALUE_ERROR_MSG = 'flock(): Argument #2 ($operation) must be one of LOCK_SH, LOCK_EX, or LOCK_UN';

    public const TYPE_ERROR_NULL_MSG = 'flock(): Argument #2 ($operation) must be of type int, null given';

    private const LOCK_NB = 4;

    public static function parseOperation(Variable $var): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            throw new \TypeError(self::TYPE_ERROR_NULL_MSG);
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            $operation = $var->toBool() ? 1 : 0;
            self::assertValidOperation($operation);

            return $operation;
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            $operation = $var->toInt();
            self::assertValidOperation($operation);

            return $operation;
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            $operation = (int) $var->toFloat();
            self::assertValidOperation($operation);

            return $operation;
        }
        if (Variable::TYPE_STRING === $var->type) {
            $raw = $var->toString();
            if (!is_numeric($raw)) {
                throw new \TypeError(\sprintf(
                    'flock(): Argument #2 ($operation) must be of type int, string given'
                ));
            }
            $operation = (int) $raw;
            self::assertValidOperation($operation);

            return $operation;
        }

        throw new \TypeError(\sprintf(
            'flock(): Argument #2 ($operation) must be of type int, %s given',
            VmStreamArg::debugTypeName($var)
        ));
    }

    public static function assertValidOperation(int $operation): void
    {
        $base = $operation & ~self::LOCK_NB;
        if ($base < 1 || $base > 3) {
            throw new \ValueError(self::VALUE_ERROR_MSG);
        }
    }
}
