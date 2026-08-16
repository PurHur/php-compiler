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
     * Z_PARAM_STR $data — soft-null DEP+coerce through PHP 8.4 (#21311, reverts #19332).
     *
     * declare(strict_types=1) caller edge still TypeErrors before coercion (#19112).
     */
    public static function resolveDataString(Frame $frame, string $function, int $argIndex = 0): string
    {
        return VmString::trimFamilyStringArgForFrame($frame, $argIndex, $function, $argIndex, 'data');
    }

    /**
     * JIT Z_PARAM_STR $data — soft-null DEP+coerce through PHP 8.4 (#21311, reverts #19332).
     */
    public static function jitDataString(Context $context, JITVariable $arg, string $function): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                $function,
                0,
                'data',
                'string',
                null,
                false
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            $function,
            0,
            'data'
        );
    }

    /** Z_PARAM_PATH $filename — non-empty path guard (php-src ext/zlib/zlib.c php_zlib_path_arg, #21877). */
    public static function resolveFilenameString(Frame $frame, string $function, int $argIndex = 0): string
    {
        return VmStreamPath::coerceNonEmptyPathArgForFrame($frame, $argIndex, $function, 'filename');
    }

    /** JIT Z_PARAM_PATH $filename — match {@see resolveFilenameString()} (#21877). */
    public static function jitFilenamePath(Context $context, JITVariable $arg, string $function, int $argIndex = 0): Value
    {
        return JitStreamPath::lowerNonEmptyPath($context, $arg, $function, $argIndex, 'filename');
    }

    /**
     * Z_PARAM_LONG $encoding — strict_types TypeError before coercion; else null DEP+→0 (#19915, #31445).
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
            // php-src zend_API.h Z_PARAM_LONG — E_DEPRECATED then coerce (#31445).
            VmNullNumberParamDeprecation::emit($frame, $function, $position, $paramName, 'int');

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
     * Z_PARAM_LONG — coerce null→0 with E_DEPRECATED in non-strict; TypeError in strict (#19948, #31445).
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
            // php-src zend_API.h Z_PARAM_LONG — E_DEPRECATED then coerce to 0 (#31445 level; encoding siblings).
            VmNullNumberParamDeprecation::emit($frame, $function, $position, $paramName, 'int');

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
