<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for generator_to_array() (issue #6025, php-src ext/standard/array.c).
 */
final class VmGeneratorArray
{
    public static function assertGenerator(Variable $value, string $funcName): Variable
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type && null !== $value->toObject()->generatorState) {
            return $value;
        }

        throw new \TypeError(sprintf(
            '%s(): Argument #1 ($generator) must be of type Generator, %s given',
            $funcName,
            VM\EnumCaseSupport::typeNameForVariable($value)
        ));
    }
}
