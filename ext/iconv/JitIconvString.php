<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for iconv_strlen/strpos/substr/strrpos (#6247; php-src ext/iconv/iconv.c). */
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
        $inputLit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
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
        $hayLit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        $needleLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
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
        $inputLit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
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
        $hayLit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        $needleLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
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
