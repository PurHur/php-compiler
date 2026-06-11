<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/** VM helpers for getopt() by-ref rest_index (#3251). */
final class VmGetopt
{
    public static function validateRestIndexByRef(Variable $var, string $fn, int $argIndex): void
    {
        if (!$var->isReference()) {
            throw new \Error(\sprintf(
                '%s(): Argument #%d ($rest_index) could not be passed by reference',
                $fn,
                $argIndex + 1
            ));
        }
        $target = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($target)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($rest_index) must be of type int, %s given',
                $fn,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($target)
            ));
        }
        if (Variable::TYPE_INTEGER !== $target->type && Variable::TYPE_NULL !== $target->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($rest_index) must be of type int, %s given',
                $fn,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($target)
            ));
        }
    }

    public static function writeRestIndex(Variable $var, int $index): void
    {
        $target = $var->resolveIndirect();
        $target->int($index);
    }
}
