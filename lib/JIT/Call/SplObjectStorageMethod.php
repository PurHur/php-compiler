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
 * SplObjectStorage::contains / ::count for self-host Block::scope (issue #816).
 */
final class SplObjectStorageMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match ($this->method) {
            'contains' => $this->callContains($context, ...$args),
            'count' => $this->callCount($context, ...$args),
            default => throw new \LogicException(
                'SplObjectStorage JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    private function callContains(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('SplObjectStorage::contains() requires an object key');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $key = $args[1];
        $keyObj = Variable::TYPE_OBJECT === $key->type
            ? $context->helper->loadValue($key)
            : $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                Variable::KIND_VARIABLE === $key->kind
                    ? JitValueBox::pointer($context, $key->value)
                    : $context->helper->loadValue($key)
            );

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
