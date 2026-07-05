<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/** flock() $operation parsing (php-src ext/standard/flock.c, issue #16575). */
final class VmFlockArg
{
    public const OPERATION_VALUE_ERROR = 'flock(): Argument #2 ($operation) must be one of LOCK_SH, LOCK_EX, or LOCK_UN';

    public static function parseOperation(Variable $var): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            throw new \ValueError(self::OPERATION_VALUE_ERROR);
        }
        if (Variable::TYPE_BOOLEAN === $var->type && !$var->toBool()) {
            throw new \ValueError(self::OPERATION_VALUE_ERROR);
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                'flock(): Argument #2 ($operation) must be of type int, %s given',
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        return $var->toInt();
    }
}
