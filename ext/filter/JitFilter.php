<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPCompiler\ext\standard\VmEngineBuiltinDeprecation;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFilterBoolean;
use PHPCompiler\JIT\Builtin\StringFilterDomain;
use PHPCompiler\JIT\Builtin\StringFilterEmail;
use PHPCompiler\JIT\Builtin\StringFilterInt;
use PHPCompiler\JIT\Builtin\StringFilterIp;
use PHPCompiler\JIT\Builtin\StringFilterMac;
use PHPCompiler\JIT\Builtin\StringFilterSanitize;
use PHPCompiler\JIT\Builtin\StringFilterUrl;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT/AOT helpers for filter_var() / filter_input() (issues #104, #6028). */
final class JitFilter
{
    private static int $blockSerial = 0;

    public static function loadFilterId(Context $context, JITVariable $filter): Value
    {
        if (JITVariable::TYPE_NULL === $filter->type) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $filter->type) {
            return $context->helper->loadValue($filter);
        }
        if (JITVariable::TYPE_VALUE === $filter->type) {
            $ptrType = $context->getTypeFromString('__value__*');
            $ptr = JITVariable::KIND_VALUE === $filter->kind
                ? $filter->value
                : $context->builder->pointerCast($filter->value, $ptrType);

            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $ptr
            );
        }

        throw new \LogicException('filter argument must be an integer constant in this compiler build');
    }

    public static function asValueVar(Context $context, JITVariable $arg): JITVariable
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $arg;
        }
        if (JITVariable::TYPE_STRING === $arg->type
            || JITVariable::TYPE_NATIVE_LONG === $arg->type
            || JITVariable::TYPE_NATIVE_BOOL === $arg->type
            || JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $arg;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $arg;
        }

        throw new \LogicException('filter_var() value type is not supported in this compiler build');
    }

    public static function boxedNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    public static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return $ptr;
    }

    /**
     * FILTER_DEFAULT / FILTER_UNSAFE_RAW: coerce scalar to string without linking
     * validate/sanitize mega-CFG helpers (#20988 AOT one-arg default).
     */
    public static function boxFilterDefault(Context $context, JITVariable $value): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            $ptrType = $context->getTypeFromString('__value__*');
            $ptr = JITVariable::KIND_VALUE === $value->kind
                ? $value->value
                : $context->builder->pointerCast($value->value, $ptrType);
            $str = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $ptr
            );
            $slot = JitValueBox::alloc($context);
            $out = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $out,
                $str
            );

            return $out;
        }
        $str = self::jitScalarToString($context, $value);
        if (null === $str) {
            return self::boxedFalse($context);
        }
        $slot = JitValueBox::alloc($context);
        $out = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $str
        );

        return $out;
    }

    /** FILTER_VALIDATE_BOOLEAN failure: null when FILTER_NULL_ON_FAILURE, else false. */
    private static function booleanFailureBox(Context $context, ?Value $nullOnFailure): Value
    {
        if (null === $nullOnFailure) {
            return self::boxedFalse($context);
        }

        $id = (string) (++self::$blockSerial);
        $nullBlock = BasicBlockHelper::append($context, 'fvb_fail_null_'.$id);
        $falseBlock = BasicBlockHelper::append($context, 'fvb_fail_false_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fvb_fail_done_'.$id);
        $context->builder->branchIf($nullOnFailure, $nullBlock, $falseBlock);

        $context->builder->positionAtEnd($nullBlock);
        $nullResult = self::boxedNull($context);
        $nullTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($falseBlock);
        $falseResult = self::boxedFalse($context);
        $falseTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($nullResult->typeOf());
        $phi->addIncoming($nullResult, $nullTail);
        $phi->addIncoming($falseResult, $falseTail);

        return $phi;
    }

    public static function loadNullOnFailureFlag(Context $context, ?JITVariable $options): Value
    {
        if (null === $options || JITVariable::TYPE_NULL === $options->type) {
            return $context->constantFromBool(false);
        }

        $optionsVal = self::loadFilterId($context, $options);
        $i64 = $context->getTypeFromString('int64');
        $flag = $i64->constInt(VmFilter::FILTER_NULL_ON_FAILURE, false);
        $masked = $context->builder->and($optionsVal, $flag);

        return $context->builder->icmp(
            Builder::INT_NE,
            $masked,
            $i64->constInt(0, false)
        );
    }

    public static function loadFilterFlags(Context $context, ?JITVariable $options): Value
    {
        if (null === $options || JITVariable::TYPE_NULL === $options->type) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return self::loadFilterId($context, $options);
    }

    private static function flagsNeedExtendedIntParse(Context $context, Value $flagsVal): bool
    {
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($flagsVal->value)) {
            return true;
        }
        $flags = (int) $lib->LLVMConstIntGetZExtValue($flagsVal->value);

        return 0 !== ($flags & (VmFilter::FILTER_FLAG_ALLOW_HEX | VmFilter::FILTER_FLAG_ALLOW_OCTAL));
    }

    /** When flag is set, rewrite boxed false validation results to null. */
    public static function applyNullOnFailure(Context $context, Value $resultPtr, Value $nullOnFailure): Value
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($resultPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $boolTag = $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTag);
        $stored = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $resultPtr
        );
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $isFalse = $context->builder->icmp(Builder::INT_EQ, $stored, $zero);
        $isBoolFalse = $context->builder->and($isBool, $isFalse);
        $shouldRewrite = $context->builder->and($nullOnFailure, $isBoolFalse);

        $id = (string) (++self::$blockSerial);
        $rewriteBlock = BasicBlockHelper::append($context, 'fv_null_on_fail_'.$id);
        $keepBlock = BasicBlockHelper::append($context, 'fv_keep_result_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fv_null_on_fail_done_'.$id);
        $context->builder->branchIf($shouldRewrite, $rewriteBlock, $keepBlock);

        $context->builder->positionAtEnd($rewriteBlock);
        $nullResult = self::boxedNull($context);
        $rewriteTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($keepBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($resultPtr->typeOf());
        $phi->addIncoming($nullResult, $rewriteTail);
        $phi->addIncoming($resultPtr, $keepBlock);

        return $phi;
    }

    public static function validateBoolean(Context $context, JITVariable $value, ?Value $nullOnFailure = null): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateBoolean($context, $value, $nullOnFailure);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $trueVal = $context->constantFromBool(true);

        if (JITVariable::TYPE_NATIVE_BOOL === $value->type) {
            JitValueBox::writeBool($context, $slot, $context->helper->loadValue($value));

            return $ptr;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $value->type) {
            return self::longToBooleanBox($context, $context->helper->loadValue($value), $nullOnFailure);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $value->type) {
            return self::doubleToBooleanBox($context, $context->helper->loadValue($value), $nullOnFailure);
        }
        if (JITVariable::TYPE_NULL === $value->type) {
            // php-src coerces null to "" before boolean validation (#17238).
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }
        if (JITVariable::TYPE_STRING !== $value->type) {
            return self::booleanFailureBox($context, $nullOnFailure);
        }

        // Compile-time string — fold via VmFilter SSOT (NestedJIT helper returns are
        // corrupt under thin AOT; #26853).
        $lit = $value->compileTimeString ?? \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($value);
        if (null !== $lit) {
            $parsed = VmFilter::parseBooleanString($lit);
            if (null === $parsed) {
                return self::booleanFailureBox($context, $nullOnFailure);
            }
            JitValueBox::writeBool($context, $slot, $context->constantFromBool($parsed));

            return $ptr;
        }

        return self::stringToBooleanBox($context, $context->helper->loadValue($value), $nullOnFailure);
    }

    public static function validateFloat(Context $context, JITVariable $value): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateFloat($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        if (JITVariable::TYPE_NATIVE_LONG === $value->type) {
            $dblTy = $context->getTypeFromString('double');
            $parsed = $context->builder->sitofp($context->helper->loadValue($value), $dblTy);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $ptr,
                $parsed
            );

            return $ptr;
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $value->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $ptr,
                $context->helper->loadValue($value)
            );

            return $ptr;
        }
        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        return self::stringToFloatBox($context, $context->helper->loadValue($value));
    }

    public static function validateInt(Context $context, JITVariable $value, ?Value $flags = null): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateInt($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $i64 = $context->getTypeFromString('int64');
        $flagsVal = $flags ?? $i64->constInt(0, false);

        if (JITVariable::TYPE_NATIVE_LONG === $value->type) {
            JitValueBox::writeLong($context, $slot, $context->helper->loadValue($value));

            return $ptr;
        }
        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        $str = $context->helper->loadValue($value);
        if (self::flagsNeedExtendedIntParse($context, $flagsVal)) {
            return self::validateIntStringWithFlags($context, $str, $flagsVal);
        }

        $isValid = self::stringIsFullInteger($context, $str);
        $parsed = self::stringToInt64($context, $str);

        $id = (string) (++self::$blockSerial);
        $okBlock = BasicBlockHelper::append($context, 'fvi_ok_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'fvi_fail_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvi_merge_'.$id);
        $context->builder->branchIf($isValid, $okBlock, $failBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $parsed);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    private static function validateIntStringWithFlags(Context $context, Value $str, Value $flagsVal): Value
    {
        StringFilterInt::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $i64 = $context->getTypeFromString('int64');
        $failSentinel = $i64->constInt(-1, true);

        $parsed = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_int_string'),
            $str,
            $flagsVal
        );
        $isOk = $context->builder->icmp(Builder::INT_NE, $parsed, $failSentinel);

        $id = (string) (++self::$blockSerial);
        $okBlock = BasicBlockHelper::append($context, 'fvi_flags_ok_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'fvi_flags_fail_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvi_flags_merge_'.$id);
        $context->builder->branchIf($isOk, $okBlock, $failBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $parsed);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    public static function validateEmail(Context $context, JITVariable $value): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateEmail($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        // Compile-time string — fold via FilterEmailValidate SSOT (NestedJIT helper
        // returns / string indexing are corrupt under thin AOT; #27068 / peer #26853).
        $lit = $value->compileTimeString ?? \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($value);
        if (null !== $lit) {
            if (!FilterEmailValidate::isValid($lit)) {
                JitValueBox::writeBool($context, $slot, $falseVal);

                return $ptr;
            }
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($lit))
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $owned
            );

            return $ptr;
        }

        // Dynamic string — keep ABI linked for capability; NestedJIT validate is best-effort.
        StringFilterEmail::ensureLinked($context);
        $str = $context->helper->loadValue($value);
        $validated = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_email'),
            $str
        );
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $validated, $null);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fve_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fve_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fve_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    public static function validateUrl(Context $context, JITVariable $value): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            StringFilterUrl::ensureLinked($context);

            return self::boxValueValidateUrl($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        // Compile-time string — fold via VmFilter SSOT (NestedJIT ?string returns are
        // corrupt under thin AOT; #27206 / peer EMAIL #27068 / BOOL #26853).
        $lit = $value->compileTimeString ?? \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($value);
        if (null !== $lit) {
            if (!VmFilter::isValidUrlSubset($lit)) {
                JitValueBox::writeBool($context, $slot, $falseVal);

                return $ptr;
            }
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($lit))
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $owned
            );

            return $ptr;
        }

        // Dynamic string — NestedJIT isValidInt + return input __string__* (#27206).
        StringFilterUrl::ensureLinked($context);
        $str = $context->helper->loadValue($value);
        $validated = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_url'),
            $str
        );
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $validated, $null);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fvu_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fvu_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvu_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    public static function validateDomain(Context $context, JITVariable $value, ?Value $flags = null): Value
    {
        StringFilterDomain::ensureLinked($context);
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateDomain($context, $value, $flags);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $flagsVal = $flags ?? $context->getTypeFromString('int64')->constInt(0, false);

        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        $str = $context->helper->loadValue($value);
        $validated = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_domain'),
            $str,
            $flagsVal
        );
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $validated, $null);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fvd_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fvd_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvd_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    public static function validateIp(Context $context, JITVariable $value, ?Value $flags = null): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            StringFilterIp::ensureLinked($context);

            return self::boxValueValidateIp($context, $value, $flags);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $flagsVal = $flags ?? $context->getTypeFromString('int64')->constInt(0, false);

        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        // Compile-time string + default flags — fold via VmFilter SSOT (#27207 / EMAIL #27068).
        $lit = $value->compileTimeString ?? \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($value);
        if (null !== $lit && null === $flags) {
            if (!VmFilter::isValidIpAddress($lit, 0)) {
                JitValueBox::writeBool($context, $slot, $falseVal);

                return $ptr;
            }
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($lit))
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $owned
            );

            return $ptr;
        }

        StringFilterIp::ensureLinked($context);
        $str = $context->helper->loadValue($value);
        $validated = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_ip'),
            $str,
            $flagsVal
        );
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $validated, $null);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fvi_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fvi_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvi_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    private static function boxValueValidateDomain(Context $context, JITVariable $arg, ?Value $flags = null): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $strVal, $null);
        $flagsVal = $flags ?? $context->getTypeFromString('int64')->constInt(0, false);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fvd_box_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fvd_box_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvd_box_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $validated = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_domain'),
            $strVal,
            $flagsVal
        );
        $validatedNull = $context->builder->icmp(Builder::INT_EQ, $validated, $null);
        $invalidBlock = BasicBlockHelper::append($context, 'fvd_box_invalid_'.$id);
        $validBlock = BasicBlockHelper::append($context, 'fvd_box_valid_'.$id);
        $context->builder->branchIf($validatedNull, $invalidBlock, $validBlock);

        $context->builder->positionAtEnd($invalidBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($validBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $strVal);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    private static function boxValueValidateIp(Context $context, JITVariable $arg, ?Value $flags = null): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $strVal, $null);
        $flagsVal = $flags ?? $context->getTypeFromString('int64')->constInt(0, false);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fvi_box_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fvi_box_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvi_box_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $validated = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_ip'),
            $strVal,
            $flagsVal
        );
        $validatedNull = $context->builder->icmp(Builder::INT_EQ, $validated, $null);
        $invalidBlock = BasicBlockHelper::append($context, 'fvi_box_invalid_'.$id);
        $validBlock = BasicBlockHelper::append($context, 'fvi_box_valid_'.$id);
        $context->builder->branchIf($validatedNull, $invalidBlock, $validBlock);

        $context->builder->positionAtEnd($invalidBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($validBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $strVal);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    public static function validateMac(Context $context, JITVariable $value): Value
    {
        StringFilterMac::ensureLinked($context);
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateMac($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        $str = $context->helper->loadValue($value);
        $validated = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_mac'),
            $str
        );
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $validated, $null);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fvm_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fvm_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvm_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    private static function boxValueValidateMac(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $strVal, $null);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fvm_box_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fvm_box_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvm_box_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $validated = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_mac'),
            $strVal
        );
        $validatedNull = $context->builder->icmp(Builder::INT_EQ, $validated, $null);
        $invalidBlock = BasicBlockHelper::append($context, 'fvm_box_invalid_'.$id);
        $validBlock = BasicBlockHelper::append($context, 'fvm_box_valid_'.$id);
        $context->builder->branchIf($validatedNull, $invalidBlock, $validBlock);

        $context->builder->positionAtEnd($invalidBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($validBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $strVal);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    private static function boxValueValidateInt(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $stringBlock = BasicBlockHelper::append($context, 'fvi_box_string');
        $failBlock = BasicBlockHelper::append($context, 'fvi_box_fail');
        $doneBlock = BasicBlockHelper::append($context, 'fvi_box_done');

        $context->builder->branchIf($hasString, $stringBlock, $failBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strVal);
        $stringResult = self::validateInt($context, $strVar);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        JitValueBox::writeLong($context, $slot, $longVal);
        $longTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($ptr->typeOf());
        $phi->addIncoming($stringResult, $stringTail);
        $phi->addIncoming($ptr, $longTail);

        return $phi;
    }

    private static function boxValueValidateEmail(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        $stringBlock = BasicBlockHelper::append($context, 'fve_box_string');
        $failBlock = BasicBlockHelper::append($context, 'fve_box_fail');
        $doneBlock = BasicBlockHelper::append($context, 'fve_box_done');

        $context->builder->branchIf($hasString, $stringBlock, $failBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strVal);
        $stringResult = self::validateEmail($context, $strVar);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($ptr->typeOf());
        $phi->addIncoming($stringResult, $stringTail);
        $phi->addIncoming($ptr, $failBlock);

        return $phi;
    }

    private static function boxValueValidateUrl(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        $stringBlock = BasicBlockHelper::append($context, 'fvu_box_string');
        $failBlock = BasicBlockHelper::append($context, 'fvu_box_fail');
        $doneBlock = BasicBlockHelper::append($context, 'fvu_box_done');

        $context->builder->branchIf($hasString, $stringBlock, $failBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strVal);
        $stringResult = self::validateUrl($context, $strVar);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($ptr->typeOf());
        $phi->addIncoming($stringResult, $stringTail);
        $phi->addIncoming($ptr, $failBlock);

        return $phi;
    }

    private static function stringIsFullInteger(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
        $one = $len->typeOf()->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'filter_int_end'
        );
        $nullEnd = $context->getTypeFromString('int8*')->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $i32->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $notConsumed = $context->builder->icmp(Builder::INT_EQ, $endPtr, $charPtr);
        $i64 = $context->getTypeFromString('int64');
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $numeric = $context->builder->and(
            $context->builder->not($notConsumed),
            $consumedAll
        );

        $i8 = $context->getTypeFromString('int8');
        $firstByte = $context->builder->load($charPtr);
        $isPlus = $context->builder->icmp(
            Builder::INT_EQ,
            $firstByte,
            $i8->constInt(ord('+'), false)
        );
        $isMinus = $context->builder->icmp(
            Builder::INT_EQ,
            $firstByte,
            $i8->constInt(ord('-'), false)
        );
        $hasSign = $context->builder->or($isPlus, $isMinus);
        $digitOffset = $context->builder->select($hasSign, $one, $zero);
        $digitOffsetI64 = $context->builder->zExt($digitOffset, $i64);
        $remaining = $context->builder->sub($len, $digitOffset);
        $moreThanOneDigit = $context->builder->icmp(Builder::INT_UGT, $remaining, $one);
        $digitPtr = $context->builder->gep($charPtr, $digitOffsetI64);
        $digitByte = $context->builder->load($digitPtr);
        $isLeadingZero = $context->builder->icmp(
            Builder::INT_EQ,
            $digitByte,
            $i8->constInt(ord('0'), false)
        );
        $leadingZeroInvalid = $context->builder->and($moreThanOneDigit, $isLeadingZero);
        $noLeadingZeroIssue = $context->builder->not($leadingZeroInvalid);
        $validDigits = $context->builder->and($numeric, $noLeadingZeroIssue);

        return $context->builder->select($isEmpty, $context->constantFromBool(false), $validDigits);
    }

    private static function stringToInt64(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'filter_int_parse'
        );
        $nullEnd = $context->getTypeFromString('int8*')->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        $i32 = $context->getTypeFromString('int32');
        $parsed = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $i32->constInt(10, false)
        );
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($parsed, $i64);
    }

    private static function longToBooleanBox(Context $context, Value $longVal, ?Value $nullOnFailure = null): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $longVal, $zero);
        $isOne = $context->builder->icmp(Builder::INT_EQ, $longVal, $one);
        $isValid = $context->builder->or($isZero, $isOne);

        $id = (string) (++self::$blockSerial);
        $validBlock = BasicBlockHelper::append($context, 'fvb_long_valid_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'fvb_long_fail_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fvb_long_done_'.$id);
        $context->builder->branchIf($isValid, $validBlock, $failBlock);

        $context->builder->positionAtEnd($validBlock);
        $boolVal = $context->builder->select(
            $isZero,
            $context->constantFromBool(false),
            $context->constantFromBool(true)
        );
        JitValueBox::writeBool($context, $slot, $boolVal);
        $validResult = $ptr;
        $validTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $failResult = self::booleanFailureBox($context, $nullOnFailure);
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($validResult->typeOf());
        $phi->addIncoming($validResult, $validTail);
        $phi->addIncoming($failResult, $failTail);

        return $phi;
    }

    private static function doubleToBooleanBox(Context $context, Value $doubleVal, ?Value $nullOnFailure = null): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $dblTy = $context->getTypeFromString('double');
        $zero = $dblTy->constReal(0.0);
        $one = $dblTy->constReal(1.0);
        $isZero = $context->builder->fcmp(Builder::REAL_OEQ, $doubleVal, $zero);
        $isOne = $context->builder->fcmp(Builder::REAL_OEQ, $doubleVal, $one);

        $id = (string) (++self::$blockSerial);
        $zeroBlock = BasicBlockHelper::append($context, 'fvb_dbl_zero_'.$id);
        $oneCheckBlock = BasicBlockHelper::append($context, 'fvb_dbl_one_chk_'.$id);
        $trueBlock = BasicBlockHelper::append($context, 'fvb_dbl_true_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'fvb_dbl_fail_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fvb_dbl_done_'.$id);
        $context->builder->branchIf($isZero, $zeroBlock, $oneCheckBlock);

        $context->builder->positionAtEnd($zeroBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $zeroResult = $ptr;
        $zeroTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($oneCheckBlock);
        $context->builder->branchIf($isOne, $trueBlock, $failBlock);

        $context->builder->positionAtEnd($trueBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $trueResult = $ptr;
        $trueTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $failResult = self::booleanFailureBox($context, $nullOnFailure);
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($zeroResult->typeOf());
        $phi->addIncoming($zeroResult, $zeroTail);
        $phi->addIncoming($trueResult, $trueTail);
        $phi->addIncoming($failResult, $failTail);

        return $phi;
    }

    private static function stringToBooleanBox(Context $context, Value $strPtr, ?Value $nullOnFailure = null): Value
    {
        // Keep helper linked for capability/shrink gates (#23612); parse inline —
        // NestedJIT/cache ABI returns are corrupt under thin AOT (#26853).
        StringFilterBoolean::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $trueVal = $context->constantFromBool(true);
        $tokenResult = self::parseBooleanStringToken($context, $strPtr);
        $i64 = $context->getTypeFromString('int64');
        $isUnknown = $context->builder->icmp(
            Builder::INT_EQ,
            $tokenResult,
            $i64->constInt(-1, true)
        );
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $tokenResult,
            $i64->constInt(0, false)
        );

        $id = (string) (++self::$blockSerial);
        $unknownBlock = BasicBlockHelper::append($context, 'fvb_str_unknown_'.$id);
        $knownBlock = BasicBlockHelper::append($context, 'fvb_str_known_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fvb_str_done_'.$id);
        $context->builder->branchIf($isUnknown, $unknownBlock, $knownBlock);

        $context->builder->positionAtEnd($unknownBlock);
        $unknownResult = self::booleanFailureBox($context, $nullOnFailure);
        $unknownTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($knownBlock);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->select($isTrue, $trueVal, $falseVal)
        );
        $knownResult = $ptr;
        $knownTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($unknownResult->typeOf());
        $phi->addIncoming($unknownResult, $unknownTail);
        $phi->addIncoming($knownResult, $knownTail);

        return $phi;
    }

    /**
     * Inline php_filter_boolean token parse — returns i64 -1/0/1 (#26853).
     * Mirrors {@see VmFilter::parseBooleanString()} (trim + case-insensitive tokens).
     */
    private static function parseBooleanStringToken(Context $context, Value $strPtr): Value
    {
        \PHPCompiler\JIT\LibcExtern::register($context);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $rawLen = $context->builder->load($context->builder->structGep($strPtr, $map['length']));
        $rawChars = $context->builder->structGep($strPtr, $map['value']);
        $id = (string) (++self::$blockSerial);

        $startSlot = $context->builder->alloca($i64, 1, 'fvb_s_'.$id);
        $endSlot = $context->builder->alloca($i64, 1, 'fvb_e_'.$id);
        $context->builder->store($i64->constInt(0, false), $startSlot);
        $context->builder->store($rawLen, $endSlot);

        // while (start < end && isspace(s[start])) start++;
        $lHead = BasicBlockHelper::append($context, 'fvb_tl_'.$id);
        $lCheck = BasicBlockHelper::append($context, 'fvb_tlc_'.$id);
        $lInc = BasicBlockHelper::append($context, 'fvb_tli_'.$id);
        $lDone = BasicBlockHelper::append($context, 'fvb_tld_'.$id);
        $context->builder->branch($lHead);
        $context->builder->positionAtEnd($lHead);
        $s = $context->builder->load($startSlot);
        $e = $context->builder->load($endSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $s, $e),
            $lCheck,
            $lDone
        );
        $context->builder->positionAtEnd($lCheck);
        $ch = $context->builder->zext(
            $context->builder->load($context->builder->gep($rawChars, $s)),
            $i32
        );
        $context->builder->branchIf(self::isAsciiSpace($context, $ch), $lInc, $lDone);
        $context->builder->positionAtEnd($lInc);
        $context->builder->store($context->builder->add($s, $i64->constInt(1, false)), $startSlot);
        $context->builder->branch($lHead);
        $context->builder->positionAtEnd($lDone);

        // while (end > start && isspace(s[end-1])) end--;
        $rHead = BasicBlockHelper::append($context, 'fvb_tr_'.$id);
        $rCheck = BasicBlockHelper::append($context, 'fvb_trc_'.$id);
        $rDec = BasicBlockHelper::append($context, 'fvb_trd_'.$id);
        $rDone = BasicBlockHelper::append($context, 'fvb_trdone_'.$id);
        $context->builder->branch($rHead);
        $context->builder->positionAtEnd($rHead);
        $s = $context->builder->load($startSlot);
        $e = $context->builder->load($endSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $e, $s),
            $rCheck,
            $rDone
        );
        $context->builder->positionAtEnd($rCheck);
        $last = $context->builder->sub($e, $i64->constInt(1, false));
        $ch = $context->builder->zext(
            $context->builder->load($context->builder->gep($rawChars, $last)),
            $i32
        );
        $context->builder->branchIf(self::isAsciiSpace($context, $ch), $rDec, $rDone);
        $context->builder->positionAtEnd($rDec);
        $context->builder->store($last, $endSlot);
        $context->builder->branch($rHead);
        $context->builder->positionAtEnd($rDone);

        $start = $context->builder->load($startSlot);
        $end = $context->builder->load($endSlot);
        $tlen = $context->builder->sub($end, $start);
        $tok = $context->builder->gep($rawChars, $start);

        $neg1 = $i64->constInt(-1, true);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $match = static function (string $lit) use ($context, $tok, $tlen, $i64, $i32): Value {
            $n = \strlen($lit);
            $lenOk = $context->builder->icmp(Builder::INT_EQ, $tlen, $i64->constInt($n, false));
            $litPtr = $context->builder->load($context->constantStringFromString($lit));
            $litChars = $context->builder->structGep(
                $litPtr,
                $context->structFieldMap['__string__']['value']
            );
            $cmp = $context->builder->call(
                $context->lookupFunction('strncasecmp'),
                $tok,
                $litChars,
                $i64->constInt($n, false)
            );
            $eq = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));

            return $context->builder->and($lenOk, $eq);
        };

        $resultSlot = $context->builder->alloca($i64, 1, 'fvb_tok_'.$id);
        $context->builder->store($neg1, $resultSlot);

        $doneTok = BasicBlockHelper::append($context, 'fvb_tok_done_'.$id);
        $emptyBlock = BasicBlockHelper::append($context, 'fvb_em_'.$id);
        $afterEmpty = BasicBlockHelper::append($context, 'fvb_ae_'.$id);
        $empty = $context->builder->icmp(Builder::INT_EQ, $tlen, $zero);
        $context->builder->branchIf($empty, $emptyBlock, $afterEmpty);
        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store($zero, $resultSlot);
        $context->builder->branch($doneTok);
        $context->builder->positionAtEnd($afterEmpty);

        $try = static function (string $lit, Value $val, string $label) use (
            $context,
            $match,
            $resultSlot,
            $doneTok,
            $id
        ): void {
            $yes = BasicBlockHelper::append($context, 'fvb_m_'.$label.'_'.$id);
            $no = BasicBlockHelper::append($context, 'fvb_n_'.$label.'_'.$id);
            $context->builder->branchIf($match($lit), $yes, $no);
            $context->builder->positionAtEnd($yes);
            $context->builder->store($val, $resultSlot);
            $context->builder->branch($doneTok);
            $context->builder->positionAtEnd($no);
        };

        $try('1', $one, '1');
        $try('0', $zero, '0');
        $try('on', $one, 'on');
        $try('no', $zero, 'no');
        $try('yes', $one, 'yes');
        $try('off', $zero, 'off');
        $try('true', $one, 'true');
        $try('false', $zero, 'false');
        $context->builder->branch($doneTok);
        $context->builder->positionAtEnd($doneTok);

        return $context->builder->load($resultSlot);
    }

    private static function isAsciiSpace(Context $context, Value $ch32): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $eq = static function (int $c) use ($context, $ch32, $i32): Value {
            return $context->builder->icmp(Builder::INT_EQ, $ch32, $i32->constInt($c, false));
        };

        return $context->builder->or(
            $context->builder->or($eq(0x20), $eq(0x09)),
            $context->builder->or(
                $context->builder->or($eq(0x0a), $eq(0x0d)),
                $context->builder->or($eq(0x0b), $eq(0x0c))
            )
        );
    }

    private static function stringToFloatBox(Context $context, Value $strPtr): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $isValid = self::stringIsFullFloat($context, $strPtr);

        $id = (string) (++self::$blockSerial);
        $okBlock = BasicBlockHelper::append($context, 'fvf_ok_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'fvf_fail_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvf_merge_'.$id);
        $context->builder->branchIf($isValid, $okBlock, $failBlock);

        $context->builder->positionAtEnd($okBlock);
        $parsed = self::stringToDouble($context, $strPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $ptr,
            $parsed
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    private static function stringIsFullFloat(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'filter_float_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $context->builder->call(
            $context->lookupFunction('strtod'),
            $charPtr,
            $endPtrSlot
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $numeric = $context->builder->and(
            $context->builder->not($isEmpty),
            $consumedAll
        );

        return $numeric;
    }

    private static function stringToDouble(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtr = $context->getTypeFromString('int8**')->constNull();

        return $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtr);
    }

    private static function boxValueValidateBoolean(Context $context, JITVariable $arg, ?Value $nullOnFailure = null): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $nullTag = $i8->constInt(JITVariable::TYPE_NULL, false);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTag);

        $id = (string) (++self::$blockSerial);
        $nullBlock = BasicBlockHelper::append($context, 'fvb_box_null_'.$id);
        $nonNullBlock = BasicBlockHelper::append($context, 'fvb_box_nonnull_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fvb_box_done_'.$id);
        $context->builder->branchIf($isNull, $nullBlock, $nonNullBlock);

        $context->builder->positionAtEnd($nullBlock);
        $nullResult = self::boxedFalse($context);
        $nullTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($nonNullBlock);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $id2 = (string) (++self::$blockSerial);
        $stringBlock = BasicBlockHelper::append($context, 'fvb_box_string_'.$id2);
        $longBlock = BasicBlockHelper::append($context, 'fvb_box_long_'.$id2);
        $innerDoneBlock = BasicBlockHelper::append($context, 'fvb_box_inner_done_'.$id2);
        $context->builder->branchIf($hasString, $stringBlock, $longBlock);

        $context->builder->positionAtEnd($stringBlock);
        $stringResult = self::stringToBooleanBox($context, $strVal, $nullOnFailure);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($innerDoneBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longResult = self::longToBooleanBox($context, $longVal, $nullOnFailure);
        $longTail = $context->builder->getInsertBlock();
        $context->builder->branch($innerDoneBlock);

        $context->builder->positionAtEnd($innerDoneBlock);
        $innerPhi = $context->builder->phi($longResult->typeOf());
        $innerPhi->addIncoming($stringResult, $stringTail);
        $innerPhi->addIncoming($longResult, $longTail);
        $nonNullResult = $innerPhi;
        $nonNullTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($nullResult->typeOf());
        $phi->addIncoming($nullResult, $nullTail);
        $phi->addIncoming($nonNullResult, $nonNullTail);

        return $phi;
    }

    private static function boxValueValidateFloat(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $id = (string) (++self::$blockSerial);
        $stringBlock = BasicBlockHelper::append($context, 'fvf_box_string_'.$id);
        $numericBlock = BasicBlockHelper::append($context, 'fvf_box_numeric_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fvf_box_done_'.$id);
        $context->builder->branchIf($hasString, $stringBlock, $numericBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strVal);
        $stringResult = self::validateFloat($context, $strVar);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($numericBlock);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $dblTy = $context->getTypeFromString('double');
        $dblVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $context->getTypeFromString('int8')->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $parsed = $context->builder->select(
            $isLong,
            $context->builder->sitofp($longVal, $dblTy),
            $dblVal
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $ptr,
            $parsed
        );
        $numericTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($ptr->typeOf());
        $phi->addIncoming($stringResult, $stringTail);
        $phi->addIncoming($ptr, $numericTail);

        return $phi;
    }

    /** Sanitizing filters via FilterSanitizeJitHelper SSOT (#11419). */
    public static function sanitize(Context $context, JITVariable $value, Value $filterVal, ?Value $flagsVal = null): Value
    {
        self::emitFilterSanitizeStringDeprecationIfNeeded($context, $filterVal);
        StringFilterSanitize::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $flags = $flagsVal ?? $i64->constInt(0, false);
        $falseVal = $context->constantFromBool(false);

        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueSanitize($context, $value, $filterVal, $flags);
        }

        $str = self::jitScalarToString($context, $value);
        if (null === $str) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        $sanitized = $context->builder->call(
            $context->lookupFunction('__compiler_filter_sanitize_string'),
            $filterVal,
            $str,
            $flags
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $sanitized
        );

        return $ptr;
    }

    private static function jitScalarToString(Context $context, JITVariable $value): ?Value
    {
        if (JITVariable::TYPE_STRING === $value->type) {
            return $context->helper->loadValue($value);
        }
        if (JITVariable::TYPE_NULL === $value->type) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        if (JITVariable::TYPE_NATIVE_LONG === $value->type) {
            return $context->builder->call(
                $context->lookupFunction('sprintf'),
                $context->builder->load($context->constantStringFromString('%ld')),
                $context->helper->loadValue($value)
            );
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $value->type) {
            return $context->builder->call(
                $context->lookupFunction('sprintf'),
                $context->builder->load($context->constantStringFromString('%F')),
                $context->helper->loadValue($value)
            );
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $value->type) {
            $id = (string) (++self::$blockSerial);
            $trueBlock = BasicBlockHelper::append($context, 'fvs_bool_true_'.$id);
            $falseBlock = BasicBlockHelper::append($context, 'fvs_bool_false_'.$id);
            $doneBlock = BasicBlockHelper::append($context, 'fvs_bool_done_'.$id);
            $isTrue = $context->helper->loadValue($value);
            $context->builder->branchIf($isTrue, $trueBlock, $falseBlock);
            $context->builder->positionAtEnd($trueBlock);
            $one = $context->builder->load($context->constantStringFromString('1'));
            $trueTail = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
            $context->builder->positionAtEnd($falseBlock);
            $zero = $context->builder->load($context->constantStringFromString(''));
            $falseTail = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
            $context->builder->positionAtEnd($doneBlock);
            $phi = $context->builder->phi($one->typeOf());
            $phi->addIncoming($one, $trueTail);
            $phi->addIncoming($zero, $falseTail);

            return $phi;
        }

        return null;
    }

    public static function isSanitizeFilterId(Context $context, Value $filterVal): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $ids = [
            VmFilter::FILTER_SANITIZE_STRING,
            VmFilter::FILTER_SANITIZE_ENCODED,
            VmFilter::FILTER_SANITIZE_SPECIAL_CHARS,
            VmFilter::FILTER_SANITIZE_FULL_SPECIAL_CHARS,
            VmFilter::FILTER_SANITIZE_EMAIL,
            VmFilter::FILTER_SANITIZE_URL,
            VmFilter::FILTER_SANITIZE_NUMBER_INT,
            VmFilter::FILTER_SANITIZE_NUMBER_FLOAT,
            VmFilter::FILTER_SANITIZE_ADD_SLASHES,
            VmFilter::FILTER_UNSAFE_RAW,
            VmFilter::FILTER_DEFAULT,
        ];
        $match = $context->constantFromBool(false);
        foreach ($ids as $id) {
            $isId = $context->builder->icmp(
                Builder::INT_EQ,
                $filterVal,
                $i64->constInt($id, false)
            );
            $match = $context->builder->or($match, $isId);
        }

        return $match;
    }

    private static function boxValueSanitize(
        Context $context,
        JITVariable $arg,
        Value $filterVal,
        Value $flags
    ): Value {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $id = (string) (++self::$blockSerial);
        $stringBlock = BasicBlockHelper::append($context, 'fvs_box_string_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'fvs_box_fail_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fvs_box_done_'.$id);
        $context->builder->branchIf($hasString, $stringBlock, $failBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strVal);
        $stringResult = self::sanitize($context, $strVar, $filterVal, $flags);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
        $isScalar = $context->builder->or(
            $context->builder->or($isLong, $isDouble),
            $context->builder->or($isBool, $isNull)
        );
        $scalarOkBlock = BasicBlockHelper::append($context, 'fvs_box_scalar_ok_'.$id);
        $hardFailBlock = BasicBlockHelper::append($context, 'fvs_box_hard_fail_'.$id);
        $context->builder->branchIf($isScalar, $scalarOkBlock, $hardFailBlock);

        $context->builder->positionAtEnd($scalarOkBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $dblVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $boolVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longVar = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $longVal);
        $dblVar = new JITVariable($context, JITVariable::TYPE_NATIVE_DOUBLE, JITVariable::KIND_VALUE, $dblVal);
        $boolVar = new JITVariable($context, JITVariable::TYPE_NATIVE_BOOL, JITVariable::KIND_VALUE, $boolVal);
        $nullVar = new JITVariable($context, JITVariable::TYPE_NULL, JITVariable::KIND_VALUE, $valuePtr);
        $longStr = self::jitScalarToString($context, $longVar);
        $dblStr = self::jitScalarToString($context, $dblVar);
        $boolStr = self::jitScalarToString($context, $boolVar);
        $nullStr = self::jitScalarToString($context, $nullVar);
        $coerced = $context->builder->select($isLong, $longStr, $dblStr);
        $coerced = $context->builder->select($isBool, $boolStr, $coerced);
        $coerced = $context->builder->select($isNull, $nullStr, $coerced);
        $sanitized = $context->builder->call(
            $context->lookupFunction('__compiler_filter_sanitize_string'),
            $filterVal,
            $coerced,
            $flags
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $sanitized
        );
        $scalarTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($hardFailBlock);
        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        JitValueBox::writeBool($context, $failSlot, $context->constantFromBool(false));
        $hardFailTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($failPtr->typeOf());
        $phi->addIncoming($stringResult, $stringTail);
        $phi->addIncoming($ptr, $scalarTail);
        $phi->addIncoming($failPtr, $hardFailTail);

        return $phi;
    }

    private static function emitFilterSanitizeStringDeprecationIfNeeded(Context $context, Value $filterVal): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $isSanitizeString = $context->builder->icmp(
            Builder::INT_EQ,
            $filterVal,
            $i64->constInt(VmFilter::FILTER_SANITIZE_STRING, false)
        );
        $emitBlock = BasicBlockHelper::append($context, 'fss_dep_emit_'.(++self::$blockSerial));
        $continueBlock = BasicBlockHelper::append($context, 'fss_dep_cont_'.self::$blockSerial);
        $context->builder->branchIf($isSanitizeString, $emitBlock, $continueBlock);
        $context->builder->positionAtEnd($emitBlock);
        JitBuiltinWarning::emitDeprecated(
            $context,
            VmEngineBuiltinDeprecation::constantMessage('FILTER_SANITIZE_STRING')
        );
        $context->builder->branch($continueBlock);
        $context->builder->positionAtEnd($continueBlock);
    }
}
