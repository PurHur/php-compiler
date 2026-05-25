<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
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
        switch ($this->method) {
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

        return $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetObjectKey'),
            $ht,
            $keyObj
        );
    }

    private function callCount(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('SplObjectStorage::count() requires the storage receiver');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $map = $context->structFieldMap['__hashtable__'];
        $num = $context->builder->load($context->builder->structGep($ht, $map['numElements']));

        return $context->builder->truncOrBitCast($num, $context->getTypeFromString('int64'));
    }

    private static function loadKeyObject(Context $context, Variable $key): Value
    {
        if (Variable::TYPE_OBJECT === $key->type) {
            return $context->helper->loadValue($key);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            Variable::KIND_VARIABLE === $key->kind
                ? JitValueBox::pointer($context, $key->value)
                : $context->helper->loadValue($key)
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
            return $context->helper->loadValue(
                $context->type->object->splBackingHashtable($receiver)
            );
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            $valPtr = Variable::KIND_VARIABLE === $receiver->kind
                ? JitValueBox::pointer($context, $receiver->value)
                : $context->helper->loadValue($receiver);

            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $valPtr
            );
        }

        throw new \LogicException(
            'SplObjectStorage method receiver must be a hashtable or object, got '
            .Variable::getStringType($receiver->type)
        );
    }
}
