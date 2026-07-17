<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
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
     * Z_PARAM_LONG $encoding for inflate_init()/deflate_init() — null coerces to 0, then ValueError
     * when invalid (php-src ext/zlib/zlib.c; #19915).
     */
    public static function parseEncodingZParamForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $paramName = 'encoding'
    ): int {
        // php-src ext/zlib/zlib.c Z_PARAM_LONG — null coerces to 0 even under caller strict_types (#19915).
        $var = $frame->calledArgs[$argIndex];
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_FLOAT === $resolved->type && null !== $frame->vmContext) {
            VmMath::warnFloatToIntPrecisionLoss($resolved->toFloat(), $frame->vmContext, $frame);
        }
        $encoding = VmMath::parseZParamLongBuiltinArg($var, $function, $userArgIndex, $paramName);
        VmZlibContext::assertValidEncoding($encoding, $function, $userArgIndex, $paramName);

        return $encoding;
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
}
