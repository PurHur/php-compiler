<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringJsonEncode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT json_encode() lowering via JsonEncodeJitHelper PHP (#6852, #9267, #27020).
 *
 * Boxed `__value__*` arrays (get_object_vars AOT) must use the hashtable ABI —
 * NestedJIT encodeValue resolveIndirect on those boxes SIGSEGVs (#27020).
 * Objects: NestedJIT encodeValue quotes class names — route via get_object_vars (#28638).
 */
final class JitJsonEncode
{
    private static int $blockSerial = 0;

    public static function encode(Context $context, JITVariable $arg, Value $flags): Value
    {
        StringJsonEncode::ensureLinked($context);

        if (JITVariable::TYPE_HASHTABLE === $arg->type || ArrayBuiltinHelper::isNativeArray($arg->type)) {
            $ht = JITVariable::TYPE_HASHTABLE === $arg->type
                ? $context->helper->loadValue($arg)
                : ArrayBuiltinHelper::loadHashTable($context, $arg);
            // Rematerialize only when NestedJIT export would see an empty Cow/view HT.
            // Unconditional alloc+overlay was emptying ordinary packed HTs under #31101
            // BB ownership (json_encode([1,2]) → "{}"). Prefer direct encode; overlay
            // remains available via replaceRecursiveCopy for true Cow cases (#26977).
            return self::stringOrFalse(
                $context,
                $context->builder->call(
                    $context->lookupFunction('__compiler_json_encode_array'),
                    $ht,
                    $flags
                )
            );
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return self::stringOrFalse(
                $context,
                self::encodeObjectPublicProps($context, $arg, $flags)
            );
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::stringOrFalse(
                $context,
                self::encodeBoxedValue($context, JitValueBox::valuePtrFromVariable($context, $arg), $flags)
            );
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::assignToPointer($context, $ptr, $arg);

        return self::stringOrFalse(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_json_encode_value'),
                $ptr,
                $flags
            )
        );
    }

    /**
     * Public props via get_object_vars + FORCE_OBJECT so empty objects encode as {} (#28638).
     * php-src: ext/json/json_encoder.c — php_json_encode_object / zend_get_properties_for
     */
    private static function encodeObjectPublicProps(Context $context, JITVariable $arg, Value $flags): Value
    {
        $boxed = JitGetObjectVars::invoke($context, $arg, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $boxed
        );
        // Skip unconditional overlay — see encode() (#31101). FORCE_OBJECT still applied.
        $force = $context->getTypeFromString('int64')->constInt(VmJsonFlags::FORCE_OBJECT, false);
        $flagsObj = $context->builder->or($flags, $force);

        return $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_array'),
            $ht,
            $flagsObj
        );
    }

    /**
     * Route boxed hashtables / objects — NestedJIT encodeValue quotes class names (#27020 / #28638).
     */
    private static function encodeBoxedValue(Context $context, Value $valuePtr, Value $flags): Value
    {
        $valuePtr = JitValueBox::normalizeValuePtr($context, $valuePtr);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isObj = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_OBJECT & 0x7f, false)
        );

        $id = (string) (++self::$blockSerial);
        $htBlock = BasicBlockHelper::append($context, 'json_encode_boxed_ht_'.$id);
        $objCheck = BasicBlockHelper::append($context, 'json_encode_boxed_objchk_'.$id);
        $objBlock = BasicBlockHelper::append($context, 'json_encode_boxed_obj_'.$id);
        $valueBlock = BasicBlockHelper::append($context, 'json_encode_boxed_value_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'json_encode_boxed_done_'.$id);
        $context->builder->branchIf($isHt, $htBlock, $objCheck);

        $context->builder->positionAtEnd($htBlock);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        // Skip unconditional overlay — see encode() (#31101).
        $htResult = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_array'),
            $ht,
            $flags
        );
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objCheck);
        $context->builder->branchIf($isObj, $objBlock, $valueBlock);

        $context->builder->positionAtEnd($objBlock);
        $objVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $valuePtr);
        $objResult = self::encodeObjectPublicProps($context, $objVar, $flags);
        $objEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($valueBlock);
        $valueResult = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_value'),
            $valuePtr,
            $flags
        );
        $valueEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr, 'json_encode_boxed_phi_'.$id);
        $phi->addIncoming($htResult, $htEnd);
        $phi->addIncoming($objResult, $objEnd);
        $phi->addIncoming($valueResult, $valueEnd);

        return $phi;
    }

    /** @return Value __value__* — false bool when {@param $result} is null (Zend json_encode failure). */
    private static function stringOrFalse(Context $context, Value $result): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'json_encode_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'json_encode_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'json_encode_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $context->builder->call($context->lookupFunction('__string__separate'), $result)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
