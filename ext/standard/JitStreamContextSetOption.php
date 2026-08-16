<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamContextRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_context_set_option() (#3448, #30645, #31422). */
final class JitStreamContextSetOption
{
    private static int $guardSeq = 0;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        // Arity checked by stream_context_set_option::call via requireArgCountRangeJit (#30584).
        $argc = \count($args);

        StreamContextRuntime::ensureLinked($context);

        JitStreamContextRequiredArg::validate($context, $args[0], 'stream_context_set_option', 1);

        // Soft-null `$wrapper_or_options` array|string — peer setcookie expires (#31422).
        if (self::operandIsNull($args[1])) {
            if ($context->callerStrictTypes) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'stream_context_set_option(): Argument #2 ($wrapper_or_options) must be of type array|string, null given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'sctxso_null_wrapper_te_cont');

                return self::emitTrue($context);
            }
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'stream_context_set_option',
                1,
                'wrapper_or_options',
                'array|string'
            );
            $args[1] = self::emptyStringArg($context);
        }

        $arg3 = $argc >= 3 ? $args[2] : null;
        $arg4 = $argc >= 4 ? $args[3] : null;
        $optionIsNull = self::operandIsNull($arg3);
        $valueProvided = null !== $arg4;

        if (self::isCompileTimeArray($args[1])) {
            if (!$optionIsNull) {
                return self::emitValueError($context, VmStreamContext::SET_OPTION_OPTION_NAME_MUST_BE_NULL_ON_ARRAY);
            }
            if ($valueProvided) {
                return self::emitValueError($context, VmStreamContext::SET_OPTION_VALUE_FORBIDDEN_ON_ARRAY);
            }

            return self::emitMergeOptions($context, $args[0], $args[1]);
        }

        if (self::isCompileTimeNonArray($args[1])) {
            if ($optionIsNull) {
                return self::emitValueError($context, VmStreamContext::SET_OPTION_OPTION_NAME_NULL_ON_STRING);
            }
            if (!$valueProvided) {
                return self::emitValueError($context, VmStreamContext::SET_OPTION_VALUE_REQUIRED_ON_STRING);
            }

            return self::emitSetSingleOption($context, $args[0], $args[1], $args[2], $args[3]);
        }

        return self::emitRuntimeWrapperDispatch(
            $context,
            $args[0],
            $args[1],
            $arg3,
            $arg4,
            $optionIsNull,
            $valueProvided
        );
    }

    private static function emitRuntimeWrapperDispatch(
        Context $context,
        JITVariable $ctxArg,
        JITVariable $wrapperArg,
        ?JITVariable $arg3,
        ?JITVariable $arg4,
        bool $optionIsNull,
        bool $valueProvided
    ): Value {
        $tag = 'sctxso'.(string) ++self::$guardSeq;
        $isArray = self::emitRuntimeIsArray($context, $wrapperArg);
        $arrBb = BasicBlockHelper::append($context, 'sctx_set_opt_arr_'.$tag);
        $strBb = BasicBlockHelper::append($context, 'sctx_set_opt_str_'.$tag);
        $joinBb = BasicBlockHelper::append($context, 'sctx_set_opt_join_'.$tag);
        $context->builder->branchIf($isArray, $arrBb, $strBb);

        $context->builder->positionAtEnd($arrBb);
        if (!$optionIsNull) {
            ExceptionBridge::emitValueErrorAndAbort($context, VmStreamContext::SET_OPTION_OPTION_NAME_MUST_BE_NULL_ON_ARRAY);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'sctx_set_opt_arr_opt_dead_'.$tag);
        } elseif ($valueProvided) {
            ExceptionBridge::emitValueErrorAndAbort($context, VmStreamContext::SET_OPTION_VALUE_FORBIDDEN_ON_ARRAY);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'sctx_set_opt_arr_val_dead_'.$tag);
        } else {
            self::emitMergeOptionsVoid($context, $ctxArg, $wrapperArg);
        }
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($strBb);
        if ($optionIsNull) {
            ExceptionBridge::emitValueErrorAndAbort($context, VmStreamContext::SET_OPTION_OPTION_NAME_NULL_ON_STRING);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'sctx_set_opt_str_opt_dead_'.$tag);
        } elseif (!$valueProvided) {
            ExceptionBridge::emitValueErrorAndAbort($context, VmStreamContext::SET_OPTION_VALUE_REQUIRED_ON_STRING);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'sctx_set_opt_str_val_dead_'.$tag);
        } else {
            assert(null !== $arg3 && null !== $arg4);
            self::emitSetSingleOptionVoid($context, $ctxArg, $wrapperArg, $arg3, $arg4);
        }
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);

        return self::emitTrue($context);
    }

    private static function emitRuntimeIsArray(Context $context, JITVariable $arg): Value
    {
        $ptr = self::loadValuePointer($context, $arg, 2);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($ptr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false)
        );

        return $context->builder->or($isArray, $isHt);
    }

    private static function emitMergeOptions(Context $context, JITVariable $ctxArg, JITVariable $optArg): Value
    {
        self::emitMergeOptionsVoid($context, $ctxArg, $optArg);

        return self::emitTrue($context);
    }

    private static function emitMergeOptionsVoid(Context $context, JITVariable $ctxArg, JITVariable $optArg): void
    {
        $ctxHt = self::loadContextArray($context, $ctxArg);
        $optHt = self::loadOptionsArray($context, $optArg, 2);
        $context->builder->call(
            $context->lookupFunction('__phpc_stream_context_merge_options'),
            $ctxHt,
            $optHt
        );
    }

    private static function emitSetSingleOption(
        Context $context,
        JITVariable $ctxArg,
        JITVariable $wrapperArg,
        JITVariable $optionArg,
        JITVariable $valueArg
    ): Value {
        self::emitSetSingleOptionVoid($context, $ctxArg, $wrapperArg, $optionArg, $valueArg);

        return self::emitTrue($context);
    }

    private static function emitSetSingleOptionVoid(
        Context $context,
        JITVariable $ctxArg,
        JITVariable $wrapperArg,
        JITVariable $optionArg,
        JITVariable $valueArg
    ): void {
        $ctxHt = self::loadContextArray($context, $ctxArg);
        $wrapperVal = self::loadValuePointer($context, $wrapperArg, 2);
        $optionVal = self::loadValuePointer($context, $optionArg, 3);
        $valueVal = self::loadValuePointer($context, $valueArg, 4);
        $context->builder->call(
            $context->lookupFunction('__phpc_stream_context_set_single_option'),
            $ctxHt,
            $wrapperVal,
            $optionVal,
            $valueVal
        );
    }

    private static function emitValueError(Context $context, string $message): Value
    {
        ExceptionBridge::emitValueErrorAndAbort($context, $message);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'sctx_set_opt_ve_dead_'.(string) ++self::$guardSeq);

        return self::emitTrue($context);
    }

    private static function emitTrue(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));

        return JitValueBox::pointer($context, $slot);
    }

    private static function isCompileTimeArray(JITVariable $arg): bool
    {
        return JITVariable::TYPE_HASHTABLE === $arg->type
            || 0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY);
    }

    private static function isCompileTimeNonArray(JITVariable $arg): bool
    {
        return !self::isCompileTimeArray($arg) && JITVariable::TYPE_VALUE !== $arg->type;
    }

    private static function operandIsNull(?JITVariable $arg): bool
    {
        return null === $arg
            || JITVariable::TYPE_NULL === $arg->type
            || $arg->isNullConstant;
    }

    private static function emptyStringArg(Context $context): JITVariable
    {
        $str = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString(''))
        );
        $str->compileTimeString = '';

        return $str;
    }

    private static function loadContextArray(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException(
            'stream_context_set_option() argument #1 must be a stream context in this compiler build'
        );
    }

    private static function loadOptionsArray(Context $context, JITVariable $arg, int $position): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException(
            "stream_context_set_option() argument #{$position} must be an array in this compiler build"
        );
    }

    private static function loadValuePointer(Context $context, JITVariable $arg, int $position): Value
    {
        // Thin AOT keeps string/scalar literals as native types; full JIT often boxes to TYPE_VALUE (#27295).
        if (
            JITVariable::TYPE_VALUE === $arg->type
            || JITVariable::TYPE_STRING === $arg->type
            || JITVariable::TYPE_NATIVE_LONG === $arg->type
            || JITVariable::TYPE_NATIVE_BOOL === $arg->type
            || JITVariable::TYPE_NATIVE_DOUBLE === $arg->type
            || JITVariable::TYPE_NULL === $arg->type
            || JITVariable::TYPE_OBJECT === $arg->type
            || JITVariable::TYPE_HASHTABLE === $arg->type
            || 0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return JitValueBox::valuePtrFromVariable($context, $arg);
        }

        throw new \LogicException(
            "stream_context_set_option() argument #{$position} must be a value in this compiler build"
        );
    }
}
