<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ModuleRegistry;

/**
 * Internal builtin {@code ::class} pseudo-constants (PHP 8.1+ FCC, Zend/zend_compile.c).
 */
final class BuiltinFunctionClassConstant
{
    /**
     * Canonical function name for {@code FunctionName::class} when FunctionName is a registered builtin.
     */
    public static function functionNameForClassOperand(string $operand): ?string
    {
        if (null !== BuiltinTypeClassConstant::classNameForTypeOperand($operand)) {
            return null;
        }
        $lc = strtolower(ltrim($operand, '\\'));
        if (!ModuleRegistry::isRegisteredBuiltinFunction($lc)) {
            return null;
        }

        return $lc;
    }
}
