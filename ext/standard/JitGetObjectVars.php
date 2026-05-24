<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_object_vars() (issue #1370). */
final class JitGetObjectVars
{
    public static function invoke(Context $context, JITVariable $objectArg): Value
    {
        $obj = self::resolveObject($context, $objectArg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        $ht = HashTableHelper::alloc($context);
        $object = $context->type->object;
        foreach ($object->allClassNamesById() as $id => $className) {
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $classBlock = BasicBlockHelper::append($context, 'gov_class_'.$id);
            $nextClass = BasicBlockHelper::append($context, 'gov_next_class_'.$id);
            $context->builder->branchIf($isClass, $classBlock, $nextClass);
            $context->builder->positionAtEnd($classBlock);
            self::appendInstanceProperties($context, $object, $obj, $className, $id, $ht);
            $context->builder->branch($nextClass);
            $context->builder->positionAtEnd($nextClass);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function resolveObject(Context $context, JITVariable $objectArg): Value
    {
        if (JITVariable::TYPE_OBJECT === $objectArg->type) {
            return $context->helper->loadValue($objectArg);
        }
        if (JITVariable::TYPE_VALUE !== $objectArg->type) {
            throw new \LogicException('get_object_vars() argument must be an object in this compiler build');
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $objectArg);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objType = $context->getTypeFromString('__object__*');
        $isObject = $context->builder->icmp(
            Builder::INT_NE,
            $obj,
            $objType->constNull()
        );
        if (!$isObject) {
            throw new \LogicException('get_object_vars() argument must be an object in this compiler build');
        }

        return $obj;
    }

    private static function appendInstanceProperties(
        Context $context,
        ObjectBuiltin $object,
        Value $obj,
        string $className,
        int $classId,
        Value $ht
    ): void {
        foreach ($object->instancePropertySets($classId) as $i => $propset) {
            $propName = $propset[1];
            $propType = $propset[2];
            $fetched = $object->propertyFetch($obj, $className, $propName);
            $keyStr = $context->builder->load($context->constantStringFromString($propName));
            if (JITVariable::TYPE_VALUE !== $propType) {
                self::storePropertyAtStringKey($context, $ht, $keyStr, $fetched, $propType);

                continue;
            }
            $isSet = self::valuePropertyIsSet($context, $fetched);
            $storeBlock = BasicBlockHelper::append($context, 'gov_prop_store_'.$classId.'_'.$i);
            $skipBlock = BasicBlockHelper::append($context, 'gov_prop_skip_'.$classId.'_'.$i);
            $context->builder->branchIf($isSet, $storeBlock, $skipBlock);
            $context->builder->positionAtEnd($storeBlock);
            self::storePropertyAtStringKey($context, $ht, $keyStr, $fetched, $propType);
            $context->builder->branch($skipBlock);
            $context->builder->positionAtEnd($skipBlock);
        }
    }

    private static function valuePropertyIsSet(Context $context, JITVariable $fetched): Value
    {
        $storage = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__object__load_value_slot'),
            $fetched->objectPropertySlot,
            $storage
        );
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($storage, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->icmp(
            Builder::INT_NE,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
    }

    private static function storePropertyAtStringKey(
        Context $context,
        Value $ht,
        Value $keyStr,
        JITVariable $fetched,
        int $propertyType
    ): void {
        if (JITVariable::TYPE_VALUE === $propertyType) {
            $dest = HashTableHelper::writableStringKeyValueBox($context, $ht, $keyStr);
            $context->builder->call(
                $context->lookupFunction('__object__load_value_slot'),
                $fetched->objectPropertySlot,
                $dest->value
            );

            return;
        }
        if (JITVariable::TYPE_HASHTABLE === $propertyType) {
            $htPtr = $context->builder->pointerCast(
                $context->builder->load($fetched->objectPropertySlot),
                $context->getTypeFromString('__hashtable__*')
            );
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyHashtable'),
                $ht,
                $keyStr,
                $htPtr
            );

            return;
        }
        HashTableHelper::setAtStringKey($context, $ht, $keyStr, $fetched);
    }
}
