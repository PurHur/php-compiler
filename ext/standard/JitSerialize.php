<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSerialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ArrayObjectJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT serialize() lowering via SerializeNestedJitHelper PHP (#6852, #20773, #27030; ensureLinked #33207).
 *
 * Type no longer always-declares `__compiler_serialize_*` after the leftover always-on shell
 * drop (#33207) — must {@see StringSerialize::ensureLinked} before lookup.
 *
 * Boxed `__value__*` arrays must use the hashtable ABI; objects use class name +
 * get_object_vars (type-tag, not null HT pointer — peer JitJsonEncode #27020).
 * ArrayObject family uses `__flags` + `__spl_ht` Zend bag (#33625; peer json_encode #33619).
 */
final class JitSerialize
{
    private const SPL_BAG_HELPER_PATH = '/ext/standard/SerializeSplArrayObjectNestedJitHelper.php';

    private const SPL_BAG_HELPER = 'PHPCompiler\\ext\\standard\\SerializeSplArrayObjectNestedJitHelper::formatBag';

    private static int $blockSerial = 0;

    public static function encode(Context $context, JITVariable $arg): Value
    {
        StringSerialize::ensureLinked($context);
        $flags = $context->getTypeFromString('int64')->constInt(0, false);

        if (JITVariable::TYPE_HASHTABLE === $arg->type || ArrayBuiltinHelper::isNativeArray($arg->type)) {
            $ht = JITVariable::TYPE_HASHTABLE === $arg->type
                ? $context->helper->loadValue($arg)
                : ArrayBuiltinHelper::loadHashTable($context, $arg);

            return $context->builder->call(
                $context->lookupFunction('__compiler_serialize_hashtable'),
                $ht,
                $flags
            );
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return self::encodeObjectOperand($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::encodeBoxedValue($context, JitValueBox::valuePtrFromVariable($context, $arg), $flags);
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_serialize_value'),
            JitValueBox::valuePtrFromVariable($context, $arg),
            $flags
        );
    }

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
        $htBlock = BasicBlockHelper::append($context, 'serialize_boxed_ht_'.$id);
        $objBlock = BasicBlockHelper::append($context, 'serialize_boxed_obj_'.$id);
        $valueBlock = BasicBlockHelper::append($context, 'serialize_boxed_value_'.$id);
        $notHt = BasicBlockHelper::append($context, 'serialize_boxed_not_ht_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'serialize_boxed_done_'.$id);
        $context->builder->branchIf($isHt, $htBlock, $notHt);

        $context->builder->positionAtEnd($htBlock);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $htResult = $context->builder->call(
            $context->lookupFunction('__compiler_serialize_hashtable'),
            $ht,
            $flags
        );
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($notHt);
        $context->builder->branchIf($isObj, $objBlock, $valueBlock);

        $context->builder->positionAtEnd($objBlock);
        $objResult = self::encodeBoxedObject($context, $valuePtr);
        $objEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($valueBlock);
        $valueResult = $context->builder->call(
            $context->lookupFunction('__compiler_serialize_value'),
            $valuePtr,
            $flags
        );
        $valueEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr, 'serialize_boxed_phi_'.$id);
        $phi->addIncoming($htResult, $htEnd);
        $phi->addIncoming($objResult, $objEnd);
        $phi->addIncoming($valueResult, $valueEnd);

        return $phi;
    }

    private static function encodeObjectOperand(Context $context, JITVariable $arg): Value
    {
        $splEncoded = self::tryEncodeSplArrayObjectBag($context, $arg);
        if (null !== $splEncoded) {
            return $splEncoded;
        }

        $className = ReflectionBuiltinHelper::getClassName($context, $arg);
        $varsBoxed = JitGetObjectVars::invoke($context, $arg, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::normalizeValuePtr($context, $varsBoxed)
        );

        return $context->builder->call(
            $context->lookupFunction('__compiler_serialize_object'),
            $className,
            $ht
        );
    }

    /**
     * ArrayObject/ArrayIterator store in `__spl_ht` — get_object_vars is empty under thin AOT (#33625).
     * php-src: ext/spl/spl_array.c — ArrayObject::__serialize [flags, storage, members, iteratorClass]
     * php-src: ext/standard/var.c — php_var_serialize object branch
     */
    private static function tryEncodeSplArrayObjectBag(Context $context, JITVariable $arg): ?Value
    {
        $objVar = self::resolveObjectReceiver($context, $arg);
        if (null === $objVar) {
            return null;
        }

        $objectType = $context->type->object;
        $aoId = $objectType->lookup('ArrayObject');
        $aiId = $objectType->lookup('ArrayIterator');
        $raiId = $objectType->lookup('RecursiveArrayIterator');
        $objPtr = $context->helper->loadValue($objVar);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $isAo = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($aoId, false)
        );
        $isAi = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($aiId, false)
        );
        $isRai = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($raiId, false)
        );
        $isSpl = $context->builder->or($context->builder->or($isAo, $isAi), $isRai);

        $id = (string) (++self::$blockSerial);
        $splBlock = BasicBlockHelper::append($context, 'serialize_spl_array_'.$id);
        $plainBlock = BasicBlockHelper::append($context, 'serialize_spl_plain_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'serialize_spl_done_'.$id);
        $context->builder->branchIf($isSpl, $splBlock, $plainBlock);

        $context->builder->positionAtEnd($splBlock);
        $aoBlock = BasicBlockHelper::append($context, 'serialize_spl_ao_'.$id);
        $aiBlock = BasicBlockHelper::append($context, 'serialize_spl_ai_'.$id);
        $raiBlock = BasicBlockHelper::append($context, 'serialize_spl_rai_'.$id);
        $notAo = BasicBlockHelper::append($context, 'serialize_spl_not_ao_'.$id);
        $context->builder->branchIf($isAo, $aoBlock, $notAo);

        $context->builder->positionAtEnd($notAo);
        $context->builder->branchIf($isAi, $aiBlock, $raiBlock);

        $context->builder->positionAtEnd($aoBlock);
        $aoResult = self::encodeSplArrayBagForClass($context, $objVar, 'ArrayObject');
        $aoEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($aiBlock);
        $aiResult = self::encodeSplArrayBagForClass($context, $objVar, 'ArrayIterator');
        $aiEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($raiBlock);
        $raiResult = self::encodeSplArrayBagForClass($context, $objVar, 'RecursiveArrayIterator');
        $raiEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($plainBlock);
        $className = ReflectionBuiltinHelper::getClassName($context, $arg);
        $varsBoxed = JitGetObjectVars::invoke($context, $arg, false);
        $plainHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::normalizeValuePtr($context, $varsBoxed)
        );
        $plainResult = $context->builder->call(
            $context->lookupFunction('__compiler_serialize_object'),
            $className,
            $plainHt
        );
        $plainEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr, 'serialize_spl_phi_'.$id);
        $phi->addIncoming($aoResult, $aoEnd);
        $phi->addIncoming($aiResult, $aiEnd);
        $phi->addIncoming($raiResult, $raiEnd);
        $phi->addIncoming($plainResult, $plainEnd);

        return $phi;
    }

    private static function encodeSplArrayBagForClass(
        Context $context,
        JITVariable $objVar,
        string $className
    ): Value {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::SPL_BAG_HELPER_PATH,
            [self::SPL_BAG_HELPER],
            '#33625'
        );
        $helperFn = $context->functions[\strtolower(self::SPL_BAG_HELPER)] ?? null;
        if (null === $helperFn) {
            throw new \LogicException(self::SPL_BAG_HELPER.' missing after NestedJIT (#33625)');
        }

        $objectType = $context->type->object;
        $htVar = $objectType->splBackingHashtable($objVar);
        $objPtr = $context->helper->loadValue($objVar);
        $flagsSlot = $objectType->propertyFetch($objPtr, $className, ArrayObjectJitHelper::PROP_FLAGS);
        $flags = JITVariable::TYPE_NATIVE_LONG === $flagsSlot->type
            ? $context->helper->loadValue($flagsSlot)
            : $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $flagsSlot)
            );

        $serFlags = $context->getTypeFromString('int64')->constInt(0, false);
        $storageWire = $context->builder->call(
            $context->lookupFunction('__compiler_serialize_hashtable'),
            $context->helper->loadValue($htVar),
            $serFlags
        );

        $classNameStr = ReflectionBuiltinHelper::getClassName($context, $objVar);
        $strMap = $context->structFieldMap['__string__'];
        $classLen = $context->builder->load(
            $context->builder->structGep($classNameStr, $strMap['length'])
        );

        $args = [
            JitNestedHelperCoerce::coerceArgForHelper($context, $classNameStr, $helperFn->getParam(0)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $classLen, $helperFn->getParam(1)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $flags, $helperFn->getParam(2)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $storageWire, $helperFn->getParam(3)->typeOf()),
        ];
        $raw = $context->builder->call($helperFn, ...$args);

        return JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $raw,
            $context->getTypeFromString('__string__*')
        );
    }

    /** @return JITVariable|null TYPE_OBJECT receiver for class_id / `__spl_ht` */
    private static function resolveObjectReceiver(Context $context, JITVariable $arg): ?JITVariable
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $arg;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            return null;
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::normalizeValuePtr($context, $valuePtr)
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $objPtr
        );
    }

    private static function encodeBoxedObject(Context $context, Value $valuePtr): Value
    {
        $objVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $valuePtr);

        return self::encodeObjectOperand($context, $objVar);
    }
}
