<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for iconv_strlen/strpos/substr/strrpos (#6247, #20208; php-src ext/iconv/iconv.c). */
final class JitIconvString
{
    public static function dispatch(Context $context, string $function, JITVariable ...$args): Value
    {
        return match ($function) {
            'iconv_strlen' => self::strlen($context, ...$args),
            'iconv_strpos' => self::strpos($context, ...$args),
            'iconv_substr' => self::substr($context, ...$args),
            'iconv_strrpos' => self::strrpos($context, ...$args),
            default => throw new \LogicException($function.'() is not wired for JIT in this compiler build'),
        };
    }

    private static function strlen(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('iconv_strlen() requires one or two arguments');
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#20208).
        if (self::rejectNullZparam($context, $args[0], 'iconv_strlen', 0, 'string')) {
            return self::unreachableIntOrFalse($context);
        }
        $inputLit = self::stringLitOrEmpty($args[0]);
        $encodingLit = self::encodingLiteral($args, 1);
        if (null !== $inputLit && null !== $encodingLit) {
            $result = VmIconv::iconvStrlen($inputLit, $encodingLit);
            if (false === $result) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }

            return $context->constantFromInteger($result, 'int64');
        }

        throw new \LogicException('iconv_strlen() JIT requires compile-time string and encoding literals in this compiler build');
    }

    private static function strpos(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('iconv_strpos() requires two to four arguments');
        }
        if (self::rejectNullZparam($context, $args[0], 'iconv_strpos', 0, 'haystack')
            || self::rejectNullZparam($context, $args[1], 'iconv_strpos', 1, 'needle')) {
            return self::unreachableIntOrFalse($context);
        }
        $hayLit = self::stringLitOrEmpty($args[0]);
        $needleLit = self::stringLitOrEmpty($args[1]);
        $offsetLit = $argc >= 3 ? self::tryCompileTimeInt($context, $args[2]) : 0;
        $encodingLit = $argc >= 4 ? self::encodingLiteral($args, 3) : 'UTF-8';
        if (null !== $hayLit && null !== $needleLit && null !== $offsetLit && null !== $encodingLit) {
            $result = VmIconv::iconvStrpos($hayLit, $needleLit, $offsetLit, $encodingLit);
            if (false === $result) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }

            return $context->constantFromInteger($result, 'int64');
        }

        throw new \LogicException('iconv_strpos() JIT requires compile-time string arguments in this compiler build');
    }

    private static function substr(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('iconv_substr() requires two to four arguments');
        }
        if (self::rejectNullZparam($context, $args[0], 'iconv_substr', 0, 'string')) {
            return self::unreachableStringOrFalse($context);
        }
        $inputLit = self::stringLitOrEmpty($args[0]);
        $offsetLit = self::tryCompileTimeInt($context, $args[1]);
        $lengthLit = $argc >= 3 ? self::tryCompileTimeOptionalInt($context, $args[2]) : null;
        $encodingLit = $argc >= 4 ? self::encodingLiteral($args, 3) : 'UTF-8';
        if (null !== $inputLit && null !== $offsetLit && null !== $encodingLit) {
            $result = VmIconv::iconvSubstr($inputLit, $offsetLit, $lengthLit, $encodingLit);
            if (false === $result) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }

            return $context->constantFromString($result);
        }

        throw new \LogicException('iconv_substr() JIT requires compile-time string arguments in this compiler build');
    }

    private static function strrpos(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('iconv_strrpos() requires two or three arguments');
        }
        if (self::rejectNullZparam($context, $args[0], 'iconv_strrpos', 0, 'haystack')
            || self::rejectNullZparam($context, $args[1], 'iconv_strrpos', 1, 'needle')) {
            return self::unreachableIntOrFalse($context);
        }
        $hayLit = self::stringLitOrEmpty($args[0]);
        $needleLit = self::stringLitOrEmpty($args[1]);
        $encodingLit = self::encodingLiteral($args, 2);
        if (null !== $hayLit && null !== $needleLit && null !== $encodingLit) {
            $result = VmIconv::iconvStrrpos($hayLit, $needleLit, $encodingLit);
            if (false === $result) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }

            return $context->constantFromInteger($result, 'int64');
        }

        throw new \LogicException('iconv_strrpos() JIT requires compile-time string arguments in this compiler build');
    }

    /**
     * Emit Z_PARAM_STR TypeError for null under PROFILE≥8.4 / strict_types (#20208).
     *
     * @return bool true when the call is aborted (unreachable)
     */
    private static function rejectNullZparam(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $param
    ): bool {
        $isNull = JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant;
        if (!$isNull) {
            return false;
        }
        $reject = $context->callerStrictTypes
            || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile();
        if (!$reject) {
            return false;
        }
        // Side-effect: emit TypeError + abort (return value unused).
        JitStringBuiltinArg::lowerZparamStr($context, $arg, $function, $argIndex, $param);

        return true;
    }

    /** Soft-null → '' outside 8.4 zparam guard; otherwise compile-time string literal. */
    private static function stringLitOrEmpty(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return '';
        }

        return JitStringBuiltinArg::compileTimeLiteral($arg);
    }

    private static function unreachableIntOrFalse(Context $context): Value
    {
        return $context->getTypeFromString('bool')->constInt(0, false);
    }

    private static function unreachableStringOrFalse(Context $context): Value
    {
        return $context->getTypeFromString('bool')->constInt(0, false);
    }

    private static function encodingLiteral(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }

    private static function tryCompileTimeInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_INTEGER === $arg->type && null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }

        return null;
    }

    private static function tryCompileTimeOptionalInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return null;
        }

        return self::tryCompileTimeInt($context, $arg);
    }
}
