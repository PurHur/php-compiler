<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\MethodVisibility;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Native LLVM lowering for get_object_vars() / get_mangled_object_vars() under standalone AOT (#26797).
 *
 * NestedJIT {@see GetObjectVarsJitHelper} cannot see user-class property metadata in a
 * standalone binary (prelinked helper / #579 stubs) — restore the pre-#16629 class-id
 * dispatch for LOAD_TYPE_STANDALONE. Embed/MCJIT keeps the PHP helper SSOT.
 *
 * php-src: ext/standard/var.c — PHP_FUNCTION(get_object_vars) / get_mangled_object_vars
 */
final class JitGetObjectVarsNative
{
    private const TYPE_ERROR = '%s(): Argument #1 ($object) must be of type object, %s given';

    public static function invoke(Context $context, JITVariable $objectArg, bool $mangledKeys = false): Value
    {
        $function = $mangledKeys ? 'get_mangled_object_vars' : 'get_object_vars';
        $compileTimeEnum = $objectArg->compileTimeEnumCase ?? null;
        if (\is_array($compileTimeEnum) && isset($compileTimeEnum['classId'], $compileTimeEnum['caseKey'])) {
            $object = $context->type->object;
            if (!$object instanceof ObjectBuiltin) {
                throw new \LogicException('get_object_vars() requires object type metadata in this compiler build');
            }
            $caseObj = $object->jitEnumCaseFromBacking((int) $compileTimeEnum['classId'], (string) $compileTimeEnum['caseKey']);
            $objPtr = JITVariable::KIND_VALUE === $caseObj->kind
                ? $caseObj->value
                : $context->builder->load($caseObj->value);
            $ht = HashTableHelper::alloc($context);
            self::appendEnumCaseObjectVars($context, $object, $objPtr, (int) $compileTimeEnum['classId'], $ht);

            return self::boxedHashtable($context, $ht);
        }

        if (JITVariable::TYPE_VALUE === $objectArg->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $objectArg);
            $typeField = $context->structFieldMap['__value__']['type'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($valuePtr, $typeField)
            );
            $i8 = $context->getTypeFromString('int8');
            $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
            $isEnumCase = $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i8->constInt(Variable::TYPE_ENUM_CASE & 0x7f, false)
            );
            $enumBlock = BasicBlockHelper::append($context, 'get_object_vars_enum_box');
            $plainBlock = BasicBlockHelper::append($context, 'get_object_vars_plain_box');
            $context->builder->branchIf($isEnumCase, $enumBlock, $plainBlock);
            $context->builder->positionAtEnd($enumBlock);
            $enumResult = self::invokeFromEnumCaseValueBox($context, $valuePtr, $function);
            $enumEnd = $context->builder->getInsertBlock();
            $doneBlock = BasicBlockHelper::append($context, 'get_object_vars_box_done');
            $context->builder->branch($doneBlock);
            $context->builder->positionAtEnd($plainBlock);
            $plainResult = self::invokeFromResolvedObject(
                $context,
                self::resolveBoxedObject($context, $objectArg, $function),
                $mangledKeys
            );
            $plainEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
            $context->builder->positionAtEnd($doneBlock);
            $valuePtrTy = $context->getTypeFromString('__value__*');
            $phi = $context->builder->phi($valuePtrTy, 'get_object_vars_box_phi');
            $phi->addIncoming($enumResult, $enumEnd);
            $phi->addIncoming($plainResult, $plainEnd);

            return $phi;
        }

        return self::invokeFromResolvedObject(
            $context,
            self::resolveObject($context, $objectArg, $function),
            $mangledKeys
        );
    }

    private static function invokeFromResolvedObject(Context $context, Value $obj, bool $mangledKeys): Value
    {
        $object = $context->type->object;
        if ($object instanceof ObjectBuiltin && [] !== $object->registeredEnumClassIds()) {
            return self::invokeWithEnumRuntimeDispatch($context, $object, $obj, $mangledKeys);
        }

        return self::invokeForPlainObject($context, $obj, $mangledKeys);
    }

    private static function invokeWithEnumRuntimeDispatch(
        Context $context,
        ObjectBuiltin $object,
        Value $obj,
        bool $mangledKeys
    ): Value {
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $enumIds = $object->registeredEnumClassIds();
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $doneBlock = $fn->appendBasicBlock('gov_enum_or_plain_done');
        $plainBlock = $fn->appendBasicBlock('gov_enum_or_plain_plain');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $i64 = $context->getTypeFromString('int64');
        $checkBlock = $entry;
        $lastIdx = \count($enumIds) - 1;
        foreach ($enumIds as $idx => $enumId) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($enumId, false)
            );
            $caseBlock = $fn->appendBasicBlock('gov_enum_or_plain_match_'.$enumId);
            $nextBlock = $idx === $lastIdx ? $plainBlock : $fn->appendBasicBlock('gov_enum_or_plain_try_'.($idx + 1));
            $context->builder->branchIf($match, $caseBlock, $nextBlock);
            $context->builder->positionAtEnd($caseBlock);
            $ht = HashTableHelper::alloc($context);
            self::appendEnumCaseObjectVars($context, $object, $obj, $enumId, $ht);
            $context->builder->store(self::boxedHashtable($context, $ht), $resultSlot);
            $context->builder->branch($doneBlock);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($plainBlock);
        $context->builder->store(self::invokeForPlainObject($context, $obj, $mangledKeys), $resultSlot);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    private static function invokeForPlainObject(Context $context, Value $obj, bool $mangledKeys): Value
    {
        $object = $context->type->object;
        if (
            !$mangledKeys
            && self::isGlobalScope($context)
            && $object instanceof ObjectBuiltin
        ) {
            $guardIds = $object->internalClassIdsForObjectVarsGuard();
            if ([] !== $guardIds) {
                return self::invokeForPlainObjectWithInternalGlobalGuard($context, $obj, $mangledKeys, $object, $guardIds);
            }
        }

        return self::invokeForPlainObjectUnrestricted($context, $obj, $mangledKeys);
    }

    private static function isGlobalScope(Context $context): bool
    {
        return 0 === $context->scope->classId && '' === $context->scope->className;
    }

    private static function invokeForPlainObjectWithInternalGlobalGuard(
        Context $context,
        Value $obj,
        bool $mangledKeys,
        ObjectBuiltin $object,
        array $guardIds
    ): Value {
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $doneBlock = $fn->appendBasicBlock('gov_internal_guard_done');
        $plainBlock = $fn->appendBasicBlock('gov_internal_guard_plain');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $checkBlock = $entry;
        $lastIdx = \count($guardIds) - 1;
        foreach ($guardIds as $idx => $id) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $matchBlock = $fn->appendBasicBlock('gov_internal_guard_match_'.$id);
            $nextBlock = $idx < $lastIdx
                ? $fn->appendBasicBlock('gov_internal_guard_try_'.$guardIds[$idx + 1])
                : $plainBlock;
            $context->builder->branchIf($match, $matchBlock, $nextBlock);
            $context->builder->positionAtEnd($matchBlock);
            $context->builder->store(self::boxedHashtable($context, HashTableHelper::alloc($context)), $resultSlot);
            $context->builder->branch($doneBlock);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($plainBlock);
        $unrestrictedEntry = $fn->appendBasicBlock('gov_internal_guard_unrestricted');
        $context->builder->branch($unrestrictedEntry);
        $context->builder->positionAtEnd($unrestrictedEntry);
        $context->builder->store(self::invokeForPlainObjectUnrestricted($context, $obj, $mangledKeys), $resultSlot);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    private static function invokeForPlainObjectUnrestricted(Context $context, Value $obj, bool $mangledKeys): Value
    {
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        $ht = HashTableHelper::alloc($context);
        $object = $context->type->object;
        if (!$object instanceof ObjectBuiltin) {
            return self::boxedHashtable($context, $ht);
        }

        $dispatchIds = $object->classIdsWithInstanceProperties();
        if ([] === $dispatchIds) {
            return self::boxedHashtable($context, $ht);
        }

        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $doneBlock = $fn->appendBasicBlock('gov_plain_done');
        $nomatchBlock = $fn->appendBasicBlock('gov_plain_nomatch');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $checkBlock = $entry;
        $lastIdx = \count($dispatchIds) - 1;
        foreach ($dispatchIds as $idx => $id) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $matchBlock = $fn->appendBasicBlock('gov_plain_match_'.$id);
            $nextBlock = $idx < $lastIdx
                ? $fn->appendBasicBlock('gov_plain_try_'.$dispatchIds[$idx + 1])
                : $nomatchBlock;
            $context->builder->branchIf($match, $matchBlock, $nextBlock);
            $context->builder->positionAtEnd($matchBlock);
            self::appendClassHierarchyProperties($context, $object, $obj, $id, $ht, $mangledKeys);
            $context->builder->store(self::boxedHashtable($context, $ht), $resultSlot);
            $context->builder->branch($doneBlock);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($nomatchBlock);
        $context->builder->store(self::boxedHashtable($context, $ht), $resultSlot);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    /**
     * Append instance properties for a runtime class id and its parent chain (#4038).
     */
    private static function appendClassHierarchyProperties(
        Context $context,
        ObjectBuiltin $object,
        Value $obj,
        int $leafClassId,
        Value $ht,
        bool $mangledKeys
    ): void {
        $chain = [];
        $currentId = $leafClassId;
        while ($currentId >= 0) {
            \array_unshift($chain, $currentId);
            $className = $object->classNameForId($currentId);
            $parentLc = $object->parentClassLc(\strtolower(\ltrim($className, '\\')));
            if (null === $parentLc) {
                break;
            }
            $currentId = $object->lookup($parentLc);
        }
        foreach ($chain as $id) {
            self::appendInstanceProperties(
                $context,
                $object,
                $obj,
                $object->classNameForId($id),
                $id,
                $ht,
                $mangledKeys
            );
        }
    }

    private static function boxedHashtable(Context $context, Value $ht): Value
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

    private static function invokeFromEnumCaseValueBox(Context $context, Value $enumCasePtr, string $function): Value
    {
        $object = $context->type->object;
        if (!$object instanceof ObjectBuiltin) {
            self::emitTypeErrorAndAbort($context, self::formatTypeError($function, 'object'));

            return self::boxedHashtable($context, HashTableHelper::alloc($context));
        }
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null === $enumMap || !isset($enumMap['class_id'])) {
            self::emitTypeErrorAndAbort($context, self::formatTypeError($function, 'object'));

            return self::boxedHashtable($context, HashTableHelper::alloc($context));
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($enumCasePtr, $enumMap['class_id'])
        );
        if (method_exists($classIdVal, 'isConstant') && $classIdVal->isConstant()) {
            $classId = (int) $classIdVal->getConstantValue();
            $caseKey = self::matchEnumCaseKeyFromStruct($context, $object, $classId, $enumCasePtr, $enumMap);
            if (null !== $caseKey) {
                $caseObj = $object->jitEnumCaseFromBacking($classId, $caseKey);
                $objPtr = JITVariable::KIND_VALUE === $caseObj->kind
                    ? $caseObj->value
                    : $context->builder->load($caseObj->value);
                $ht = HashTableHelper::alloc($context);
                self::appendEnumCaseObjectVars($context, $object, $objPtr, $classId, $ht);

                return self::boxedHashtable($context, $ht);
            }
        }
        $given = JitOperandTypeLabel::compileTimeEnumClassName($context, new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            $enumCasePtr
        )) ?? 'object';
        self::emitTypeErrorAndAbort($context, self::formatTypeError($function, $given));

        return self::boxedHashtable($context, HashTableHelper::alloc($context));
    }

    /**
     * @param array<string, int> $enumMap
     */
    private static function matchEnumCaseKeyFromStruct(
        Context $context,
        ObjectBuiltin $object,
        int $classId,
        Value $enumCasePtr,
        array $enumMap
    ): ?string {
        if (!$object->enumHasBacking($classId) || !isset($enumMap['backing'])) {
            $caseKeys = $object->enumCaseOrderForClass($classId);

            return $caseKeys[0] ?? null;
        }
        $backingField = $context->builder->structGep($enumCasePtr, $enumMap['backing']);
        $backingPtr = $context->builder->pointerCast(
            $backingField,
            $context->getTypeFromString('__value__*')
        );
        $backedType = $object->enumBackedTypeFor($classId);
        foreach ($object->enumCaseOrderForClass($classId) as $caseKey) {
            $expected = $object->enumCaseBackingScalarForCase($classId, $caseKey);
            if ('int' === $backedType && \is_int($expected)) {
                $actual = $context->builder->call($context->lookupFunction('__value__readLong'), $backingPtr);
                if (method_exists($actual, 'isConstant') && $actual->isConstant()
                    && (int) $actual->getConstantValue() === $expected) {
                    return $caseKey;
                }
            }
            if ('string' === $backedType && \is_string($expected)) {
                $actual = $context->builder->call($context->lookupFunction('__value__readString'), $backingPtr);
                if (method_exists($actual, 'isConstant') && $actual->isConstant()) {
                    $expectedPtr = $context->builder->load($context->constantStringFromString($expected));
                    $cmp = $context->builder->call(
                        $context->lookupFunction('strcmp'),
                        self::stringDataPtr($context, $actual),
                        self::stringDataPtr($context, $expectedPtr)
                    );
                    if ($cmp->isConstant() && 0 === (int) $cmp->getConstantValue()) {
                        return $caseKey;
                    }
                }
            }
        }

        return null;
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        return $context->builder->structGep($strPtr, $context->structFieldIndex($strPtr, 'value'));
    }

    private static function appendEnumCaseObjectVars(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr,
        int $enumClassId,
        Value $ht
    ): void {
        $nameFetched = $object->fetchEnumCaseBuiltinProperty($objPtr, $enumClassId, 'name');
        $nameKey = $context->builder->load($context->constantStringFromString('name'));
        HashTableHelper::setAtStringKey($context, $ht, $nameKey, $nameFetched);
        if (!$object->enumHasBacking($enumClassId)) {
            return;
        }
        $valueFetched = $object->fetchEnumCaseBuiltinProperty($objPtr, $enumClassId, 'value');
        $valueKey = $context->builder->load($context->constantStringFromString('value'));
        HashTableHelper::setAtStringKey($context, $ht, $valueKey, $valueFetched);
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
        // Mask IS_REFCOUNTED — boxed objects often carry type|0x80 (#28638).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'get_object_vars_ok');
        $errBlock = BasicBlockHelper::append($context, 'get_object_vars_err');
        $context->builder->branchIf($isObject, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        $given = JitOperandTypeLabel::givenLabel($context, $objectArg);
        self::emitTypeErrorAndAbort($context, self::formatTypeError($function, $given));

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
        // get_object_vars(): scope-visible props only (zend_check_property_access / #23430).
        // get_mangled_object_vars(): all set props with mangled keys (ext/standard/var.c).
        $propSets = $mangledKeys
            ? $object->instancePropertySets($classId)
            : $object->instancePropertySetsVisibleFromScope(
                $classId,
                '' !== $context->scope->className ? $context->scope->className : null
            );
        foreach ($propSets as $i => $propset) {
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
