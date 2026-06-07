<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_class_vars() (issue #3159). */
final class JitGetClassVars
{
    public static function invoke(Context $context, JITVariable $classArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($classArg);
        if (null === $literal) {
            throw new \LogicException(
                'get_class_vars() class name must be a string literal in this compiler build'
            );
        }

        return self::invokeForClassName($context, $literal);
    }

    private static function invokeForClassName(Context $context, string $className): Value
    {
        $lc = strtolower(ltrim($className, '\\'));
        $vm = $context->runtime->vmContext;
        if (null !== $vm && isset($vm->classes[$lc])) {
            return self::wrapHashTable(
                $context,
                self::emitFromVmClass($context, $vm->classes[$lc])
            );
        }

        $object = $context->type->object;
        if (!$object->hasUserDeclaredClass($className)) {
            return self::returnFalse($context);
        }

        return self::wrapHashTable(
            $context,
            self::emitFromObjectRegistry($context, $className)
        );
    }

    private static function emitFromVmClass(Context $context, \PHPCompiler\VM\ClassEntry $entry): Value
    {
        $table = VmReflection::getClassVarsArray($entry)->toArray();
        $ht = HashTableHelper::alloc($context);
        foreach ($table->iterate(false) as $key => $valueVar) {
            $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
            self::storeVmVariable($context, $ht, $keyStr, $valueVar);
        }

        return $ht;
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
                $entry = $defaults[$slotIndex];
                self::storeCompileTimeDefault($context, $ht, $keyStr, $entry);
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

    /**
     * @param array{type: int, value: int|float|bool|string|null} $entry
     */
    private static function storeStaticPropertyDefault(
        Context $context,
        Value $ht,
        Value $keyStr,
        array $entry
    ): void {
        $compileEntry = [
            'propertyType' => $entry['type'],
            'type' => $entry['type'],
            'value' => $entry['value'],
        ];
        self::storeCompileTimeDefault($context, $ht, $keyStr, $compileEntry);
    }

    /**
     * @param array{propertyType: int, type: int, value: int|float|bool|string|null} $entry
     */
    private static function storeCompileTimeDefault(
        Context $context,
        Value $ht,
        Value $keyStr,
        array $entry
    ): void {
        switch ($entry['type']) {
            case Variable::TYPE_NATIVE_LONG:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int64')->constInt((int) $entry['value'], false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case Variable::TYPE_NATIVE_BOOL:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int1')->constInt($entry['value'] ? 1 : 0, false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case Variable::TYPE_NATIVE_DOUBLE:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_DOUBLE,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('double')->constFloat((float) $entry['value'])
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case Variable::TYPE_STRING:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString((string) $entry['value']))
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            default:
                return;
        }
    }

    private static function storeVmVariable(
        Context $context,
        Value $ht,
        Value $keyStr,
        VMVariable $value
    ): void {
        $resolved = $value->resolveIndirect();
        switch ($resolved->type) {
            case VMVariable::TYPE_INTEGER:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_BOOLEAN:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int1')->constInt($resolved->toBool() ? 1 : 0, false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_FLOAT:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_DOUBLE,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('double')->constFloat($resolved->toFloat())
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_STRING:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString($resolved->toString()))
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_NULL:
                return;
            default:
                return;
        }
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
