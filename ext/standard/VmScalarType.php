<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * Zend scalar casts on enum case operands for intval/floatval (issue #5623, ext/standard/type.c).
 */
final class VmScalarType
{
    public static function tryEnumCaseToInt(Frame $frame, Variable $value): ?int
    {
        return EnumCaseSupport::tryCastToInt($value, $frame->vmContext, $frame);
    }

    public static function tryEnumCaseToFloat(Frame $frame, Variable $value): ?float
    {
        return EnumCaseSupport::tryCastToFloat($value, $frame->vmContext, $frame);
    }
}
