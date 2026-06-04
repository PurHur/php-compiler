<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\MethodVisibility;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_object_vars() (issue #1370). */
final class JitGetObjectVars
{
    private const TYPE_ERROR = '%s(): Argument #1 ($object) must be of type object, %s given';

    public static function invoke(Context $context, JITVariable $objectArg, bool $mangledKeys = false): Value
    {
        $function = $mangledKeys ? 'get_mangled_object_vars' : 'get_object_vars';
        $obj = self::resolveObject($context, $objectArg, $function);
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
            self::appendInstanceProperties($context, $object, $obj, $className, $id, $ht, $mangledKeys);
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

    private static function resolveObject(Context $context, JITVariable $objectArg, string $function): Value
    {
        if (JITVariable::TYPE_OBJECT === $objectArg->type) {
            return $context->helper->loadValue($objectArg);
        }
        if (JITVariable::TYPE_VALUE === $objectArg->type) {
            return self::resolveBoxedObject($context, $objectArg, $function);
        }

        self::emitTypeErrorAndAbort($context, self::scalarTypeError($objectArg->type, $function));

        return $context->getTypeFromString('__object__*')->constNull();
    }

    private static function resolveBoxedObject(Context $context, JITVariable $objectArg, string $function): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $objectArg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'get_object_vars_ok');
        $errBlock = BasicBlockHelper::append($context, 'get_object_vars_err');
        $context->builder->branchIf($isObject, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, self::formatTypeError($function, 'mixed'));

        $context->builder->positionAtEnd($okBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );

        return $obj;
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function scalarTypeError(int $type, string $function): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return self::formatTypeError($function, 'int');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return self::formatTypeError($function, 'float');
            case JITVariable::TYPE_NATIVE_BOOL:
                return self::formatTypeError($function, 'bool');
            case JITVariable::TYPE_STRING:
                return self::formatTypeError($function, 'string');
            case JITVariable::TYPE_NULL:
                return self::formatTypeError($function, 'null');
            default:
                return self::formatTypeError($function, 'mixed');
        }
    }

    private static function formatTypeError(string $function, string $given): string
    {
        return \sprintf(self::TYPE_ERROR, $function, $given);
    }

    private static function appendInstanceProperties(
        Context $context,
        ObjectBuiltin $object,
        Value $obj,
        string $className,
        int $classId,
        Value $ht,
        bool $mangledKeys = false
    ): void {
        foreach ($object->instancePropertySets($classId) as $i => $propset) {
            $propName = $propset[1];
            $propType = $propset[2];
            $fetched = $object->propertyFetch($obj, $className, $propName);
            $key = $mangledKeys
                ? self::manglePropertyKey($propName, $object->propertyVisibility($classId, $propName), $className)
                : $propName;
            $keyStr = $context->builder->load($context->constantStringFromString($key));
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

        $notNull = $context->builder->icmp(
            Builder::INT_NE,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
        $notUndefined = $context->builder->icmp(
            Builder::INT_NE,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_UNDEFINED, false)
        );

        return $context->builder->and($notNull, $notUndefined);
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

    /**
     * Zend property hash key for ZEND_PROP_PURPOSE_DEBUG (php-src zend_mangle_property_name).
     */
    private static function manglePropertyKey(string $propName, int $visibility, string $declaringClassName): string
    {
        if (MethodVisibility::isPublic($visibility)) {
            return $propName;
        }
        if (($visibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return "\0*\0".$propName;
        }

        return "\0".$declaringClassName."\0".$propName;
    }
}
