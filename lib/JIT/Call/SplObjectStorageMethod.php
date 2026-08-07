<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SplObjectStorage instance methods for self-host spine (#816, #1998).
 */
final class SplObjectStorageMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        switch (strtolower($this->method)) {
            case 'attach':
                return $this->callAttach($context, ...$args);
            case 'contains':
            case 'offsetexists':
                return $this->callContains($context, ...$args);
            case 'count':
                return $this->callCount($context, ...$args);
            case 'offsetget':
                return $this->callOffsetGet($context, ...$args);
            case 'offsetset':
                return $this->callOffsetSet($context, ...$args);
            case 'rewind':
                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileRewind($context, $args[0]);
            case 'next':
                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileNext($context, $args[0]);
            case 'valid':
                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileValid($context, $args[0]);
            case 'key':
                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileKey($context, $args[0]);
            case 'current':
                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileCurrent($context, $args[0]);
            case 'getinfo':
                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileGetInfo($context, $args[0]);
            case 'setinfo':
                if (count($args) < 2) {
                    throw new \LogicException('SplObjectStorage::setInfo() requires an info value');
                }

                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileSetInfo($context, $args[0], $args[1]);
            default:
                throw new \LogicException(
                    'SplObjectStorage JIT lowering is not implemented for '.$this->method.'()'
                );
        }
    }

    private function callAttach(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('SplObjectStorage::attach() requires an object key');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = self::loadKeyObject($context, $args[1]);
        if (count($args) >= 3) {
            HashTableHelper::setAtObjectKey($context, $ht, $keyObj, $args[2]);
        } else {
            $writable = HashTableHelper::writableObjectKeyValueBox($context, $ht, $keyObj);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $writable->value)
            );
        }

        return self::voidResult($context);
    }

    private function callOffsetSet(Context $context, Variable ...$args): Value
    {
        if (count($args) < 3) {
            throw new \LogicException('SplObjectStorage::offsetSet() requires object key and value');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = self::loadKeyObject($context, $args[1]);
        HashTableHelper::setAtObjectKey($context, $ht, $keyObj, $args[2]);

        return self::voidResult($context);
    }

    private function callOffsetGet(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('SplObjectStorage::offsetGet() requires an object key');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = self::loadKeyObject($context, $args[1]);
        $fetched = HashTableHelper::readObjectKeyToValueBox($context, $ht, $keyObj);

        return $fetched->value;
    }

    private function callContains(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('SplObjectStorage::contains() requires an object key');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = self::loadKeyObject($context, $args[1]);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetObjectKey'),
            $ht,
            $keyObj
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $isSet);

        return $slot;
    }

    private function callCount(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('SplObjectStorage::count() requires the storage receiver');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $map = $context->structFieldMap['__hashtable__'];
        $num = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->truncOrBitCast($num, $context->getTypeFromString('int64'))
        );

        return $slot;
    }

    private static function loadKeyObject(Context $context, Variable $key): Value
    {
        if (Variable::TYPE_OBJECT === $key->type) {
            return self::materializeObjectPointer($context, $key);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $key)
        );
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function backingHashtable(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_HASHTABLE === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (Variable::TYPE_OBJECT === $receiver->type) {
            $obj = self::materializeObjectPointer($context, $receiver);

            return $context->helper->loadValue(
                $context->type->object->splBackingHashtable(
                    new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj)
                )
            );
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            return self::backingHashtableFromValueBox($context, $receiver);
        }

        throw new \LogicException(
            'SplObjectStorage method receiver must be a hashtable or object, got '
            .Variable::getStringType($receiver->type)
        );
    }

    private static function backingHashtableFromValueBox(Context $context, Variable $receiver): Value
    {
        $valPtr = JitValueBox::valuePtrFromVariable($context, $receiver);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $isHashtable = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $fromObject = BasicBlockHelper::append($context, 'spl_ht_from_object');
        $fromHashtable = BasicBlockHelper::append($context, 'spl_ht_from_hashtable');
        $empty = BasicBlockHelper::append($context, 'spl_ht_empty');
        $merge = BasicBlockHelper::append($context, 'spl_ht_merge');
        $context->builder->branchIf($isObject, $fromObject, $empty);
        $context->builder->positionAtEnd($empty);
        $context->builder->branchIf($isHashtable, $fromHashtable, $merge);
        $context->builder->positionAtEnd($fromObject);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valPtr
        );
        $objHt = $context->helper->loadValue(
            $context->type->object->splBackingHashtable(
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj)
            )
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($fromHashtable);
        $boxedHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $htPhi = $context->builder->phi($objHt->typeOf());
        $htPhi->addIncoming($objHt, $fromObject);
        $htPhi->addIncoming($boxedHt, $fromHashtable);
        $htPhi->addIncoming($objHt->typeOf()->constNull(), $empty);

        return $htPhi;
    }

    /** Resolve storage receiver to __object__* without property-lvalue indirection (#8422). */
    private static function materializeObjectPointer(Context $context, Variable $receiver): Value
    {
        if (null !== $receiver->objectPropertySlot && Variable::TYPE_OBJECT === ($receiver->objectPropertyType ?? null)) {
            return $context->builder->pointerCast(
                $context->builder->load($receiver->objectPropertySlot),
                $context->getTypeFromString('__object__*')
            );
        }

        return $context->helper->loadValue($receiver);
    }
}
