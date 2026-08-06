<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * json_decode()/json_validate() $json operand — profile-aware Z_PARAM parity (#21223, #27995).
 *
 * json_decode: Z_PARAM_STR soft-null (DEP+coerce) on 8.4 (#21223). json_validate: Z_PARAM_STRING
 * TypeError on null under PROFILE≥8.4 (ext/json/json.c, #26190).
 */
final class JsonStringOperandArg
{
    public static function vmJson(Frame $frame, string $function, int $argIndex = 0): string
    {
        if (self::requiresStrictOperand($function)) {
            return VmString::requireStringBuiltinArg(
                $frame->calledArgs[$argIndex],
                $function,
                $argIndex,
                'json'
            );
        }

        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, $function, 'json')->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            $function,
            $argIndex,
            'json'
        );
    }

    public static function jitJson(Context $context, JITVariable $arg, string $function, int $argIndex = 0): Value
    {
        if (self::requiresStrictOperand($function)) {
            return JitStringBuiltinArg::lowerRequiredString($context, $arg, $function, $argIndex, 'json');
        }

        return JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, $function, $argIndex, 'json');
    }

    private static function requiresStrictOperand(string $function): bool
    {
        return 'json_validate' === $function
            && CompilerVersion::jsonValidateStringOperandRequiresStrictType();
    }
}
