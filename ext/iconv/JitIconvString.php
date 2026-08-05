<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringIconvSubstr;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for iconv_strlen/strpos/substr/strrpos (#6247, #20208, #27197; php-src ext/iconv/iconv.c). */
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
        // Soft-null $string on 8.4 (#21197); strict_types still TypeErrors.
        if (self::abortOnStrictNull($context, $args[0], 'iconv_strlen', 0, 'string')) {
            return self::unreachableIntOrFalse($context);
        }
        $inputLit = self::softNullStringLit($context, $args[0], 'iconv_strlen', 0, 'string');
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
        if (self::abortOnStrictNull($context, $args[0], 'iconv_strpos', 0, 'haystack')
            || self::abortOnStrictNull($context, $args[1], 'iconv_strpos', 1, 'needle')) {
            return self::unreachableIntOrFalse($context);
        }
        $hayLit = self::softNullStringLit($context, $args[0], 'iconv_strpos', 0, 'haystack');
        $needleLit = self::softNullStringLit($context, $args[1], 'iconv_strpos', 1, 'needle');
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
        if (self::abortOnStrictNull($context, $args[0], 'iconv_substr', 0, 'string')) {
            return self::unreachableStringOrFalse($context);
        }

        $folded = self::tryFoldSubstr($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $input = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'iconv_substr', 0, 'string')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'iconv_substr', 0, 'string');

        $i64 = $context->getTypeFromString('int64');
        $offset = JitLongArg::lower($context, $args[1], 'iconv_substr() offset');

        $lengthOrOmitted = $i64->constInt(IconvStringJitHelper::LENGTH_OMITTED, true);
        if ($argc >= 3) {
            $lengthIsNull = JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant;
            if (!$lengthIsNull) {
                $lengthOrOmitted = JitLongArg::lower($context, $args[2], 'iconv_substr() length');
            }
        }

        if ($argc >= 4) {
            $encodingIsNull = JITVariable::TYPE_NULL === $args[3]->type || $args[3]->isNullConstant;
            $encoding = $encodingIsNull
                ? $context->builder->load($context->constantStringFromString('UTF-8'))
                : ($context->callerStrictTypes
                    ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[3], 'iconv_substr', 3, 'encoding')
                    : JitStringBuiltinArg::lowerZparamStr($context, $args[3], 'iconv_substr', 3, 'encoding'));
        } else {
            $encoding = $context->builder->load($context->constantStringFromString('UTF-8'));
        }

        StringIconvSubstr::ensureLinked($context);
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_iconv_substr'),
            $input,
            $offset,
            $lengthOrOmitted,
            $encoding
        );

        return self::materializeStringOrFalse($context, $result);
    }

    /**
     * @param JITVariable[] $args
     */
    private static function tryFoldSubstr(Context $context, array $args): ?Value
    {
        $argc = \count($args);
        $inputLit = self::softNullStringLit($context, $args[0], 'iconv_substr', 0, 'string');
        $offsetLit = self::tryCompileTimeInt($context, $args[1]);
        if (null === $inputLit || null === $offsetLit) {
            return null;
        }

        $lengthLit = null;
        if ($argc >= 3) {
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                $lengthLit = null;
            } else {
                $lengthLit = self::tryCompileTimeInt($context, $args[2]);
                if (null === $lengthLit) {
                    return null;
                }
            }
        }

        $encodingLit = $argc >= 4 ? self::encodingLiteral($args, 3) : 'UTF-8';
        if (null === $encodingLit) {
            return null;
        }

        $result = VmIconv::iconvSubstr($inputLit, $offsetLit, $argc >= 3 ? $lengthLit : null, $encodingLit);
        if (false === $result) {
            $slot = JitValueBox::alloc($context);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return JitValueBox::pointer($context, $slot);
        }

        // constantFromString() is a C-string global (unknown*) — box as __string__ like
        // JitIconv::foldCompileTime so AOT can infer the builtin return type (#27197).
        $strPtr = $context->builder->load($context->constantStringFromString($result));

        return self::materializeStringOrFalse($context, $strPtr);
    }

    /** Box `__string__*` / null into `__value__*` (string or false) for AOT type inference. */
    private static function materializeStringOrFalse(Context $context, Value $contents): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'iconv_substr_fail');
        $okBlock = BasicBlockHelper::append($context, 'iconv_substr_ok');
        $doneBlock = BasicBlockHelper::append($context, 'iconv_substr_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $contents
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function strrpos(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('iconv_strrpos() requires two or three arguments');
        }
        if (self::abortOnStrictNull($context, $args[0], 'iconv_strrpos', 0, 'haystack')
            || self::abortOnStrictNull($context, $args[1], 'iconv_strrpos', 1, 'needle')) {
            return self::unreachableIntOrFalse($context);
        }
        $hayLit = self::softNullStringLit($context, $args[0], 'iconv_strrpos', 0, 'haystack');
        $needleLit = self::softNullStringLit($context, $args[1], 'iconv_strrpos', 1, 'needle');
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
     * Emit TypeError for null under caller strict_types only (#21197 soft-null otherwise).
     *
     * @return bool true when the call is aborted (unreachable)
     */
    private static function abortOnStrictNull(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $param
    ): bool {
        $isNull = JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant;
        if (!$isNull || !$context->callerStrictTypes) {
            return false;
        }
        JitStringBuiltinArg::lowerZparamStr($context, $arg, $function, $argIndex, $param);

        return true;
    }

    /** Soft-null → '' with E_DEPRECATED; otherwise compile-time string literal (#21197). */
    private static function softNullStringLit(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $param
    ): ?string {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            JitStringBuiltinArg::emitNullStringParamDeprecation($context, $function, $argIndex, $param);

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
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
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

        return null;
    }
}
