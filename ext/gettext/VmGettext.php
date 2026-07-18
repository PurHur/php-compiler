<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * VM gettext helpers — php-src-strict arg coercion + {@see VmGettextNative} (#3449, #6608).
 */
final class VmGettext
{
    /**
     * Z_PARAM_STR $message / $msgid* — null TypeError on PROFILE=8.4 (#20209, gettext.stub.php).
     */
    public static function coerceMsgidArg(Variable $var, string $function, int $argIndex, string $param): string
    {
        return VmString::coerceZparamStrBuiltinArg($var, $function, $argIndex, $param);
    }

    /**
     * Z_PARAM_STR $domain — null TypeError on PROFILE=8.4 (#20209, gettext.stub.php).
     */
    public static function coerceDomainArg(Variable $var, string $function, int $argIndex, string $param): string
    {
        return VmString::coerceZparamStrBuiltinArg($var, $function, $argIndex, $param);
    }

    public static function msgidArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $param
    ): string {
        return VmString::zparamStrBuiltinArgForFrame($frame, $argIndex, $function, $userArgIndex, $param);
    }

    public static function domainArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $param
    ): string {
        return VmString::zparamStrBuiltinArgForFrame($frame, $argIndex, $function, $userArgIndex, $param);
    }

    public static function coerceNullableDirectoryArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $param
    ): ?string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $function, $argIndex, $param);
    }

    public static function coerceNullableDomainArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $param
    ): ?string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $function, $argIndex, $param);
    }

    public static function coerceCategoryArg(Variable $var, string $function, int $argIndex, string $param): int
    {
        return VmMath::parseIntBuiltinArg($var, $function, $argIndex, $param);
    }

    public static function coerceCountArg(Variable $var, string $function, int $argIndex, string $param): int
    {
        return VmMath::parseIntBuiltinArg($var, $function, $argIndex, $param);
    }

    public static function writeStringOrFalseReturn(Variable $ret, string|false $value): void
    {
        if (false === $value) {
            $ret->bool(false);

            return;
        }
        $ret->string($value);
    }
}
