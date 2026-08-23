<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\MethodVisibility;
use PHPLLVM\Value;

/**
 * Thin standalone AOT materialization for get_class_vars() (#27229, re-#16713 / #3159).
 *
 * Helper-runtime {@see \PHPCompiler\ext\standard\GetClassVarsJitHelper} calls
 * {@see \PHPCompiler\ext\standard\VmReflection} which is not in the thin helper TU —
 * ExternalMethod stubs those calls to silent NULL (#579). For literal class names
 * under user-script AOT, emit public defaults from the compile-unit {@see Type\Object_}
 * registry (same shape as pre-#16713 emitFromObjectRegistry).
 *
 * NestedJIT / VM keeps {@see StringGetClassVars} → GetClassVarsJitHelper for scope (#23531).
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(get_class_vars) / add_class_vars
 */
final class GetClassVarsRuntime
{
    public static function emitForClassName(Context $context, string $className): Value
    {
        $object = $context->type->object;
        if (!$object->hasUserDeclaredClass($className)) {
            return self::returnFalse($context);
        }

        return self::wrapHashTable($context, self::emitFromObjectRegistry($context, $className));
    }

    /**
     * ReflectionClass::getDefaultProperties() — all visibilities; skip parent privates (#34091).
     *
     * php-src: zim_ReflectionClass_getDefaultProperties / reflection_class_get_default_properties
     */
    public static function emitDefaultPropertiesForClassId(Context $context, int $classId): Value
    {
        return self::wrapHashTable(
            $context,
            self::emitDefaultPropertiesHashTable($context, $classId)
        );
    }

    /**
     * ReflectionClass::getStaticProperties() — static slot defaults/values (#34118).
     *
     * Thin AOT has no live static stores in the name→value map; emit compile-time
     * defaults (same source as getDefaultProperties statics). Skip parent-privates.
     *
     * php-src: zim_ReflectionClass_getStaticProperties
     */
    public static function emitStaticPropertiesForClassId(Context $context, int $classId): Value
    {
        return self::wrapHashTable(
            $context,
            self::emitStaticPropertiesHashTable($context, $classId)
        );
    }

    /** Empty array box when ReflectionClass name does not match a compile-unit class. */
    public static function emitEmptyDefaultProperties(Context $context): Value
    {
        return self::wrapHashTable($context, HashTableHelper::alloc($context));
    }

    public static function emitEmptyStaticProperties(Context $context): Value
    {
        return self::wrapHashTable($context, HashTableHelper::alloc($context));
    }

    private static function emitFromObjectRegistry(Context $context, string $className): Value
    {
        $object = $context->type->object;
        $classId = $object->lookup($className);
        $ht = HashTableHelper::alloc($context);
        /** @var array<string, true> $seen */
        $seen = [];
        $currentId = $classId;
        for ($depth = 0; $depth < 64; ++$depth) {
            $defaults = $object->propertyDefaultEntries($currentId);
            foreach ($object->instancePropertySets($currentId) as $propset) {
                $propName = $propset[1];
                if (isset($seen[$propName])) {
                    continue;
                }
                if (!MethodVisibility::isPublic($object->propertyVisibility($currentId, $propName))) {
                    continue;
                }
                $slotIndex = $propset[3];
                $keyStr = $context->builder->load($context->constantStringFromString($propName));
                if (!isset($defaults[$slotIndex])) {
                    $seen[$propName] = true;

                    continue;
                }
                self::storeCompileTimeDefault($context, $ht, $keyStr, $defaults[$slotIndex]);
                $seen[$propName] = true;
            }
            $parentName = $object->parentClassDisplayName($object->classNameForId($currentId));
            if (null === $parentName) {
                break;
            }
            $currentId = $object->lookup($parentName);
        }
        foreach ($object->publicStaticPropertyDefaultEntries($classId) as $propName => $entry) {
            if (isset($seen[$propName])) {
                continue;
            }
            $keyStr = $context->builder->load($context->constantStringFromString($propName));
            self::storeStaticPropertyDefault($context, $ht, $keyStr, $entry);
            $seen[$propName] = true;
        }

        return $ht;
    }

    private static function emitDefaultPropertiesHashTable(Context $context, int $classId): Value
    {
        $object = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        /** @var array<string, true> $seen */
        $seen = [];
        $reflectedName = strtolower(ltrim($object->classNameForId($classId), '\\'));
        $currentId = $classId;
        for ($depth = 0; $depth < 64; ++$depth) {
            $defaults = $object->propertyDefaultEntries($currentId);
            foreach ($object->instancePropertySets($currentId) as $propset) {
                $propName = $propset[1];
                if (isset($seen[$propName])) {
                    continue;
                }
                $vis = $object->propertyVisibility($currentId, $propName);
                // php-src default_properties_table: parent privates are not visible (#34091).
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                    $declName = strtolower(ltrim(
                        $object->instancePropertyDeclaringClassName($currentId, $propName),
                        '\\'
                    ));
                    if ($declName !== $reflectedName) {
                        $seen[$propName] = true;
                        continue;
                    }
                }
                $slotIndex = $propset[3];
                $keyStr = $context->builder->load($context->constantStringFromString($propName));
                if (!isset($defaults[$slotIndex])) {
                    // Typed without default omitted; untyped without default → null (Zend).
                    if ($object->propertySlotRequiresTypedInitGuard($currentId, $slotIndex)) {
                        $seen[$propName] = true;
                        continue;
                    }
                    self::storeNullDefault($context, $ht, $keyStr);
                    $seen[$propName] = true;
                    continue;
                }
                self::storeCompileTimeDefault($context, $ht, $keyStr, $defaults[$slotIndex]);
                $seen[$propName] = true;
            }
            $parentName = $object->parentClassDisplayName($object->classNameForId($currentId));
            if (null === $parentName) {
                break;
            }
            $currentId = $object->lookup($parentName);
        }
        foreach ($object->reflectionDefaultStaticPropertyEntries($classId) as $propName => $entry) {
            if (isset($seen[$propName])) {
                continue;
            }
            $keyStr = $context->builder->load($context->constantStringFromString($propName));
            self::storeStaticPropertyDefault($context, $ht, $keyStr, $entry);
            $seen[$propName] = true;
        }

        return $ht;
    }

    private static function emitStaticPropertiesHashTable(Context $context, int $classId): Value
    {
        $object = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        foreach ($object->reflectionDefaultStaticPropertyEntries($classId) as $propName => $entry) {
            $keyStr = $context->builder->load($context->constantStringFromString($propName));
            self::storeStaticPropertyDefault($context, $ht, $keyStr, $entry);
        }

        return $ht;
    }

    /**
     * @param array{type: int, value: int|float|bool|string|null} $entry
     */
    private static function storeStaticPropertyDefault(
        Context $context,
        Value $ht,
        Value $keyStr,
        array $entry
    ): void {
        self::storeCompileTimeDefault($context, $ht, $keyStr, [
            'propertyType' => $entry['type'],
            'type' => $entry['type'],
            'value' => $entry['value'],
        ]);
    }

    /**
     * @param array{propertyType?: int, type: int, value: int|float|bool|string|null} $entry
     */
    private static function storeCompileTimeDefault(
        Context $context,
        Value $ht,
        Value $keyStr,
        array $entry
    ): void {
        $type = $entry['type'];
        $value = $entry['value'] ?? null;
        // Untyped statics are TYPE_VALUE slots; materialize from the scalar default (#27229).
        if (JITVariable::TYPE_VALUE === $type) {
            if (\is_int($value)) {
                $type = JITVariable::TYPE_NATIVE_LONG;
            } elseif (\is_float($value)) {
                $type = JITVariable::TYPE_NATIVE_DOUBLE;
            } elseif (\is_bool($value)) {
                $type = JITVariable::TYPE_NATIVE_BOOL;
            } elseif (\is_string($value)) {
                $type = JITVariable::TYPE_STRING;
            } elseif (null === $value) {
                self::storeNullDefault($context, $ht, $keyStr);

                return;
            } else {
                return;
            }
        }
        if (null === $value && JITVariable::TYPE_NULL !== $type) {
            // Explicit null default on a typed/nullable slot (#34091).
            if (JITVariable::TYPE_NATIVE_LONG === $type
                || JITVariable::TYPE_NATIVE_DOUBLE === $type
                || JITVariable::TYPE_NATIVE_BOOL === $type
                || JITVariable::TYPE_STRING === $type) {
                // fall through only when value is set; null scalar → store null
                self::storeNullDefault($context, $ht, $keyStr);

                return;
            }
        }
        switch ($type) {
            case JITVariable::TYPE_NULL:
                self::storeNullDefault($context, $ht, $keyStr);

                return;
            case JITVariable::TYPE_NATIVE_LONG:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int64')->constInt((int) $value, false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case JITVariable::TYPE_NATIVE_BOOL:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_DOUBLE,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('double')->constFloat((float) $value)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case JITVariable::TYPE_STRING:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString((string) $value))
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            default:
                return;
        }
    }

    private static function storeNullDefault(Context $context, Value $ht, Value $keyStr): void
    {
        $nullVar = new JITVariable(
            $context,
            JITVariable::TYPE_NULL,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('__value__*')->constNull()
        );
        $nullVar->isNullConstant = true;
        HashTableHelper::setAtStringKey($context, $ht, $keyStr, $nullVar);
    }

    private static function wrapHashTable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function returnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return $ptr;
    }
}
