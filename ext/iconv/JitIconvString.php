<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringIconvSubstr;
use PHPCompiler\JIT\Builtin\StringStrpos;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for iconv_strlen/strpos/substr/strrpos (#6247, #20208, #27197, #34272, #34277). */
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

    /** Dummy IR value after catchable ArgumentCountError abort (#30891). */
    public static function dummyAfterArgcAbort(Context $context, string $function): Value
    {
        if ('iconv_substr' === $function) {
            return self::unreachableStringOrFalse($context);
        }

        return self::unreachableIntOrFalse($context);
    }

    private static function strlen(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
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

        $input = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'iconv_strlen', 0, 'string')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'iconv_strlen', 0, 'string');
        $defaultEncoding = IconvEncodingState::getInternalEncoding();
        if ($argc >= 2) {
            $encodingIsNull = JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant;
            $encodingLitRt = JitStringBuiltinArg::compileTimeLiteral($args[1]);
            if ($encodingIsNull || (null !== $encodingLitRt && '' === $encodingLitRt)) {
                $encoding = $context->builder->load($context->constantStringFromString($defaultEncoding));
            } elseif (null !== $encodingLitRt) {
                $encoding = $context->builder->load($context->constantStringFromString(
                    VmIconv::resolveOptionalEncoding($encodingLitRt)
                ));
            } else {
                $encoding = $context->callerStrictTypes
                    ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'iconv_strlen', 1, 'encoding')
                    : JitStringBuiltinArg::lowerZparamStr($context, $args[1], 'iconv_strlen', 1, 'encoding');
            }
        } else {
            $encoding = $context->builder->load($context->constantStringFromString($defaultEncoding));
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringIconvSubstr::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        return JitNestedHelperCoerce::callHelper(
            $context,
            StringIconvSubstr::strlenHelper($context),
            [$input, $encoding]
        );
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
        $encodingLit = self::encodingLiteral($args, 3);
        if (null !== $hayLit && null !== $needleLit && null !== $offsetLit && null !== $encodingLit) {
            $result = VmIconv::iconvStrpos($hayLit, $needleLit, $offsetLit, $encodingLit);
            if (false === $result) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }

            return $context->constantFromInteger($result, 'int64');
        }

        $hay = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'iconv_strpos', 0, 'haystack')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'iconv_strpos', 0, 'haystack');
        $needle = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'iconv_strpos', 1, 'needle')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'iconv_strpos', 1, 'needle');
        $i64 = $context->getTypeFromString('int64');
        $offset = $argc >= 3
            ? JitLongArg::lower($context, $args[2], 'iconv_strpos() offset')
            : $i64->constInt(0, false);
        $defaultEncoding = IconvEncodingState::getInternalEncoding();
        if ($argc >= 4) {
            $encodingIsNull = JITVariable::TYPE_NULL === $args[3]->type || $args[3]->isNullConstant;
            $encodingLitRt = JitStringBuiltinArg::compileTimeLiteral($args[3]);
            if ($encodingIsNull || (null !== $encodingLitRt && '' === $encodingLitRt)) {
                $encoding = $context->builder->load($context->constantStringFromString($defaultEncoding));
            } elseif (null !== $encodingLitRt) {
                $encoding = $context->builder->load($context->constantStringFromString(
                    VmIconv::resolveOptionalEncoding($encodingLitRt)
                ));
            } else {
                $encoding = $context->callerStrictTypes
                    ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[3], 'iconv_strpos', 3, 'encoding')
                    : JitStringBuiltinArg::lowerZparamStr($context, $args[3], 'iconv_strpos', 3, 'encoding');
            }
        } else {
            $encoding = $context->builder->load($context->constantStringFromString($defaultEncoding));
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringIconvSubstr::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $found = JitNestedHelperCoerce::callHelper(
            $context,
            StringIconvSubstr::strposHelper($context),
            [$hay, $needle, $offset, $encoding]
        );

        return StringStrpos::boxFoundOffset($context, $found);
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

        // 4-arg ABI: length=-1 omitted (peer mb_substr #34256). Extreme omit tokens broke NestedJIT.
        $lengthOrOmitted = $i64->constInt(-1, true);
        if ($argc >= 3) {
            $lengthIsNull = JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant;
            if (!$lengthIsNull) {
                $lengthOrOmitted = JitLongArg::lower($context, $args[2], 'iconv_substr() length');
            }
        }

        $defaultEncoding = IconvEncodingState::getInternalEncoding();
        if ($argc >= 4) {
            $encodingIsNull = JITVariable::TYPE_NULL === $args[3]->type || $args[3]->isNullConstant;
            $encodingLit = JitStringBuiltinArg::compileTimeLiteral($args[3]);
            if ($encodingIsNull || (null !== $encodingLit && '' === $encodingLit)) {
                // Empty/null encoding → internal charset (#29497).
                $encoding = $context->builder->load($context->constantStringFromString($defaultEncoding));
            } else {
                $encoding = $context->callerStrictTypes
                    ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[3], 'iconv_substr', 3, 'encoding')
                    : JitStringBuiltinArg::lowerZparamStr($context, $args[3], 'iconv_substr', 3, 'encoding');
            }
        } else {
            $encoding = $context->builder->load($context->constantStringFromString($defaultEncoding));
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringIconvSubstr::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            StringIconvSubstr::helperFunction($context),
            [$input, $offset, $lengthOrOmitted, $encoding]
        );

        return self::materializeHelperStringOrFalse($context, $raw);
    }

    /** NestedJIT string|null → `__value__*` string|false (peer JitMbChrOrd / #34272). */
    private static function materializeHelperStringOrFalse(Context $context, Value $raw): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'iconv_substr_box');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $isMiss = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $failBlock = BasicBlockHelper::append($context, 'iconv_substr_fail');
        $okBlock = BasicBlockHelper::append($context, 'iconv_substr_ok');
        $doneBlock = BasicBlockHelper::append($context, 'iconv_substr_done');
        $context->builder->branchIf($isMiss, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $strPtr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $strPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
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

        $encodingLit = self::encodingLiteral($args, 3);
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
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
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

        $hay = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'iconv_strrpos', 0, 'haystack')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'iconv_strrpos', 0, 'haystack');
        $needle = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'iconv_strrpos', 1, 'needle')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'iconv_strrpos', 1, 'needle');
        $defaultEncoding = IconvEncodingState::getInternalEncoding();
        if ($argc >= 3) {
            $encodingIsNull = JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant;
            $encodingLitRt = JitStringBuiltinArg::compileTimeLiteral($args[2]);
            if ($encodingIsNull || (null !== $encodingLitRt && '' === $encodingLitRt)) {
                $encoding = $context->builder->load($context->constantStringFromString($defaultEncoding));
            } elseif (null !== $encodingLitRt) {
                $encoding = $context->builder->load($context->constantStringFromString(
                    VmIconv::resolveOptionalEncoding($encodingLitRt)
                ));
            } else {
                $encoding = $context->callerStrictTypes
                    ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[2], 'iconv_strrpos', 2, 'encoding')
                    : JitStringBuiltinArg::lowerZparamStr($context, $args[2], 'iconv_strrpos', 2, 'encoding');
            }
        } else {
            $encoding = $context->builder->load($context->constantStringFromString($defaultEncoding));
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringIconvSubstr::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $found = JitNestedHelperCoerce::callHelper(
            $context,
            StringIconvSubstr::strrposHelper($context),
            [$hay, $needle, $encoding]
        );

        return StringStrpos::boxFoundOffset($context, $found);
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
            return IconvEncodingState::getInternalEncoding();
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type || $args[$index]->isNullConstant) {
            return IconvEncodingState::getInternalEncoding();
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }
        $lit = $args[$index]->compileTimeString ?? null;
        if (null === $lit) {
            return null;
        }

        // Empty encoding → internal/default charset (#29497).
        return VmIconv::resolveOptionalEncoding($lit);
    }

    private static function tryCompileTimeInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }

        return null;
    }
}
