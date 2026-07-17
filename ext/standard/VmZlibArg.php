<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** Shared VM argument parsing for ext/zlib builtins (php-src ext/zlib/zlib.c, issue #4497). */
final class VmZlibArg
{
    /**
     * Z_PARAM_STR $data — null TypeError on 8.4 forward profile (#19332, #19112).
     *
     * Also covers declare(strict_types=1) caller edge before coercion.
     */
    public static function resolveDataString(Frame $frame, string $function, int $argIndex = 0): string
    {
        return VmString::zparamStrBuiltinArgForFrame($frame, $argIndex, $function, $argIndex, 'data');
    }

    /**
     * JIT Z_PARAM_STR $data — null TypeError on 8.4 forward profile (#19332).
     */
    public static function jitDataString(Context $context, JITVariable $arg, string $function): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                $function,
                0,
                'data'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            $function,
            0,
            'data'
        );
    }

    /** Z_PARAM_STR $filename with declare(strict_types=1) caller edge (#19119). */
    public static function resolveFilenameString(Frame $frame, string $function, int $argIndex = 0): string
    {
        return InternalStrictArg::resolveCoercibleStringArg($frame, $argIndex, $function, 'filename');
    }

    /**
     * Z_PARAM_LONG $encoding — strict_types TypeError before coercion; else null→0 then ValueError (#19915).
     */
    public static function resolveEncodingInt(
        Frame $frame,
        int $argIndex,
        string $function,
        int $position,
        string $paramName
    ): int {
        $var = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            if (InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d ($%s) must be of type int, null given',
                    $function,
                    $position,
                    $paramName
                ));
            }

            return 0;
        }

        return self::requireInt($var, $function, $position, $paramName);
    }

    public static function requireInt(
        Variable $var,
        string $function,
        int $position,
        string $paramName
    ): int {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $position,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        return $var->toInt();
    }

    /**
     * Z_PARAM_LONG — coerce null→0 in non-strict; TypeError in strict (#19948).
     */
    public static function coerceInt(
        Frame $frame,
        int $argIndex,
        string $function,
        int $position,
        string $paramName
    ): int {
        $var = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            if (InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d ($%s) must be of type int, null given',
                    $function,
                    $position,
                    $paramName
                ));
            }

            return 0;
        }

        return self::requireInt($var, $function, $position, $paramName);
    }

    public static function requireLevel(
        Variable $var,
        string $function,
        int $position = 2,
        string $paramName = 'level'
    ): int {
        $level = self::requireInt($var, $function, $position, $paramName);
        if ($level < -1 || $level > 9) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($%s) must be between -1 and 9',
                $function,
                $position,
                $paramName
            ));
        }

        return $level;
    }

    /**
     * Z_PARAM_LONG level with range check — coerce null→0 in non-strict (#19948).
     */
    public static function coerceLevel(
        Frame $frame,
        int $argIndex,
        string $function,
        int $position = 2,
        string $paramName = 'level'
    ): int {
        $level = self::coerceInt($frame, $argIndex, $function, $position, $paramName);
        if ($level < -1 || $level > 9) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($%s) must be between -1 and 9',
                $function,
                $position,
                $paramName
            ));
        }

        return $level;
    }
}
