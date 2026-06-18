<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StatCacheRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for clearstatcache() — clears JIT/AOT stat cache (#9110). */
final class JitClearstatcache
{
    public static function invoke(Context $context, int $argc, JITVariable ...$args): Value
    {
        StatCacheRuntime::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');

        $clearRealpath = $i1->constInt(1, false);
        $filename = $strPtr->constNull();

        if ($argc >= 1) {
            $clearRealpath = self::lowerClearRealpathFlag($context, $args[0]);
        }
        if ($argc >= 2) {
            $filename = JitStringBuiltinArg::lower($context, $args[1], 'clearstatcache', 1, 'filename');
        }

        $context->builder->call(
            $context->lookupFunction(StatCacheRuntime::FN_CLEAR),
            $i32->constInt($argc, false),
            $clearRealpath,
            $filename
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    /**
     * Avoid {@see JitBoolArg::lower()} boxed lowering in AOT {main} — it mislinks on standalone builds (#9110).
     */
    private static function lowerClearRealpathFlag(Context $context, JITVariable $arg): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $literal = $arg->compileTimeString ?? null;
        if (null !== $literal) {
            $lower = strtolower($literal);
            if (\in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
                return $context->constantFromBool(true);
            }
            if (\in_array($lower, ['0', 'false', 'off', 'no', ''], true)) {
                return $context->constantFromBool(false);
            }
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $zero = $context->getTypeFromString('int64')->constInt(0, false);

            return $context->builder->icmp(
                Builder::INT_NE,
                $context->helper->loadValue($arg),
                $zero
            );
        }
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $i1->constInt(0, false);
        }
        if (null !== $arg->compileTimeLong) {
            return $context->constantFromBool(0 !== $arg->compileTimeLong);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedBoolFlag($context, $arg);
        }

        return $i1->constInt(1, false);
    }

    private static function lowerBoxedBoolFlag(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_BOOLEAN, false)
        );
        $boolCase = BasicBlockHelper::append($context, 'clear_bool_flag_case');
        $longCase = BasicBlockHelper::append($context, 'clear_bool_flag_long_case');
        $done = BasicBlockHelper::append($context, 'clear_bool_flag_done');
        $context->builder->branchIf($isBool, $boolCase, $longCase);

        $context->builder->positionAtEnd($boolCase);
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $boolVal = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($firstByte),
            $i8->constInt(0, false)
        );
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longCase);
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $longVal = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr),
            $zero
        );
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($boolVal, $boolEnd);
        $phi->addIncoming($longVal, $longEnd);

        return $phi;
    }
}
