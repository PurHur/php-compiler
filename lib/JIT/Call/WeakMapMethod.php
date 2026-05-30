<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\WeakRefNative;
use PHPCompiler\JIT\Builtin\WeakRefRuntime;
use PHPCompiler\JIT\Builtin\WeakRefSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** WeakMap ArrayAccess + count for JIT (#3667). */
final class WeakMapMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match (strtolower($this->method)) {
            'offsetset' => $this->callOffsetSet($context, ...$args),
            'offsetget' => $this->callOffsetGet($context, ...$args),
            'offsetexists' => $this->callOffsetExists($context, ...$args),
            'count' => $this->callCount($context, ...$args),
            default => throw new \LogicException(
                'WeakMap JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    private function callOffsetSet(Context $context, Variable ...$args): Value
    {
        if (count($args) < 3) {
            throw new \LogicException('WeakMap::offsetSet() expects map, key, and value');
        }
        WeakRefRuntime::ensureLinked($context);
        WeakRefNative::registerDeclarations($context);

        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = WeakRefSetup::loadObjectFromArg($context, $args[1]);
        [$keyStr, $keyBuf] = self::buildObjectKey($context, $keyObj);
        HashTableHelper::setAtStringKey($context, $ht, $keyStr, $args[2]);
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call(
            $context->lookupFunction('phpc_weakref_register_map'),
            $context->builder->pointerCast($keyObj, $i8p),
            $context->builder->pointerCast($ht, $i8p),
            $keyBuf
        );

        return self::voidResult($context);
    }

    private function callOffsetGet(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('WeakMap::offsetGet() expects map and key');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = WeakRefSetup::loadObjectFromArg($context, $args[1]);
        [$keyStr] = self::buildObjectKey($context, $keyObj);
        $fetched = HashTableHelper::readStringKeyToValueBox($context, $ht, $keyStr);

        return $fetched->value;
    }

    private function callOffsetExists(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('WeakMap::offsetExists() expects map and key');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = WeakRefSetup::loadObjectFromArg($context, $args[1]);
        [$keyStr] = self::buildObjectKey($context, $keyObj);

        return $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
    }

    private function callCount(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('WeakMap::count() requires the map receiver');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $map = $context->structFieldMap['__hashtable__'];
        $num = $context->builder->load($context->builder->structGep($ht, $map['numElements']));

        return $context->builder->truncOrBitCast($num, $context->getTypeFromString('int64'));
    }

    /**
     * @return array{0: Value, 1: Value} [__string__* key, int8* buf]
     */
    private static function buildObjectKey(Context $context, Value $keyObj): array
    {
        $i8 = $context->getTypeFromString('int8');
        $buf = $context->builder->alloca($i8, 32, 'weakmap_key_buf');
        $sizeT = $context->getTypeFromString('size_t');
        WeakRefSetup::formatObjectKey($context, $keyObj, $buf, $sizeT->constInt(32, false));
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $buf
        );
        $keyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $context->getTypeFromString('int64')),
            $buf
        );

        return [$keyStr, $buf];
    }

    private static function backingHashtable(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue(
                $context->type->object->weakMapBackingHashtable($receiver)
            );
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                Variable::KIND_VARIABLE === $receiver->kind
                    ? JitValueBox::pointer($context, $receiver->value)
                    : $context->helper->loadValue($receiver)
            );

            return $context->helper->loadValue(
                $context->type->object->weakMapBackingHashtable(
                    new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj)
                )
            );
        }

        throw new \LogicException(
            'WeakMap receiver must be object, got '.Variable::getStringType($receiver->type)
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
}
