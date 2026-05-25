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
 * SplObjectStorage instance methods for self-host Block::scope (issues #816, #1998).
 *
 * Bracket access and methods share pointer-string keys via HashTableHelper::objectPointerAsStringKey.
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
            default:
                throw new \LogicException(
                    'SplObjectStorage JIT lowering is not implemented for '.$this->method.'()'
                );
        }
    }

    private function callAttach(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('SplObjectStorage::attach() requires object key');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyStr = self::stringKeyFromArg($context, $args[1]);
        $keyVal = $context->helper->loadValue($keyStr);
        if (isset($args[2]) && Variable::TYPE_NULL !== $args[2]->type) {
            HashTableHelper::setAtStringKey($context, $ht, $keyVal, $args[2]);
        } else {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyLong'),
                $ht,
                $keyVal,
                $context->constantFromInteger(0, 'int64')
            );
        }

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    private function callContains(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('SplObjectStorage::contains() requires an object key');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyStr = self::stringKeyFromArg($context, $args[1]);

        return $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $context->helper->loadValue($keyStr)
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

    private function callOffsetGet(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('SplObjectStorage::offsetGet() requires object key');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyStr = self::stringKeyFromArg($context, $args[1]);
        $boxed = HashTableHelper::readStringKeyToValueBox(
            $context,
            $ht,
            $context->helper->loadValue($keyStr)
        );

        return JitValueBox::pointer($context, $boxed->value);
    }

    private function callOffsetSet(Context $context, Variable ...$args): Value
    {
        if (count($args) < 3) {
            throw new \LogicException('SplObjectStorage::offsetSet() requires object key and value');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyStr = self::stringKeyFromArg($context, $args[1]);
        HashTableHelper::setAtStringKey(
            $context,
            $ht,
            $context->helper->loadValue($keyStr),
            $args[2]
        );

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    private static function stringKeyFromArg(Context $context, Variable $key): Variable
    {
        if (Variable::TYPE_OBJECT === $key->type) {
            return HashTableHelper::objectPointerAsStringKey($context, $key);
        }
        if (Variable::TYPE_VALUE === $key->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                Variable::KIND_VARIABLE === $key->kind
                    ? JitValueBox::pointer($context, $key->value)
                    : $context->helper->loadValue($key)
            );
            $objVar = new Variable(
                $context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $obj
            );

            return HashTableHelper::objectPointerAsStringKey($context, $objVar);
        }

        throw new \LogicException(
            'SplObjectStorage keys must be objects in this compiler build'
        );
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
