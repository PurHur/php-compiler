<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for object::propertyIsInitialized() — delegates slot guards to
 * {@see \PHPCompiler\VM\PropertyIsInitializedJitHelper} (#10186).
 */
final class PropertyIsInitializedLlvm
{
    private const HELPER_PATH = '/VM/PropertyIsInitializedJitHelper.php';

    private const VALUE_BOX_HELPER = 'PHPCompiler\\VM\\PropertyIsInitializedJitHelper::valueBoxSlotIsInitialized';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE_BOX_HELPER,
    ];

    public static function lower(Context $context, Variable $receiver, Variable $propNameArg): Value
    {
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('propertyIsInitialized() must be called on an object');
        }

        $propLiteral = JitStringArg::compileTimeLiteral($propNameArg);
        JitStringBuiltinArg::lower($context, $propNameArg, 'propertyIsInitialized', 0, 'name');

        $object = $context->type->object;
        assert($object instanceof Object_);

        $objPtr = Variable::KIND_VALUE === $receiver->kind
            ? $receiver->value
            : $context->builder->load($receiver->value);

        if (null === $propLiteral) {
            return self::lowerRuntimeClassDynamicName($context, $object, $objPtr, $propNameArg);
        }

        $receiverClass = $receiver->objectPropertyClassName;
        if (null !== $receiverClass && '' !== $receiverClass) {
            return self::lowerKnownClassLiteralName($context, $object, $objPtr, $receiverClass, $propLiteral);
        }

        return self::lowerRuntimeClassLiteralName($context, $object, $objPtr, $propLiteral);
    }

    private static function lowerKnownClassLiteralName(
        Context $context,
        Object_ $object,
        Value $objPtr,
        string $receiverClass,
        string $propName
    ): Value {
        $resolved = $object->resolvePropertySlot($receiverClass, $propName);
        if (null === $resolved) {
            self::emitPropertyNotExists($context, $receiverClass, $propName);

            return self::unreachableFalse($context);
        }
        [$declaringClassId, $slotIndex] = $resolved;
        $declaringClass = $object->classNameForId($declaringClassId);
        self::emitVisibilityGuard(
            $context,
            $object,
            $declaringClassId,
            $declaringClass,
            $propName,
            strtolower(ltrim($receiverClass, '\\'))
        );

        return self::emitSlotInitialized($context, $object, $objPtr, $receiverClass, $propName, $declaringClassId, $slotIndex);
    }

    private static function lowerRuntimeClassLiteralName(
        Context $context,
        Object_ $object,
        Value $objPtr,
        string $propName
    ): Value {
        $map = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('prop_is_init_done');
        $exit = $fn->appendBasicBlock('prop_is_init_exit');
        $fallback = $fn->appendBasicBlock('prop_is_init_fallback');
        $destSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int1'));
        $i64 = $context->getTypeFromString('int64');
        $checkBlock = $entry;
        $classEntries = array_filter(
            $object->allClassNamesById(),
            fn (string $className): bool => null !== $object->resolvePropertySlot($className, $propName)
        );
        if ([] === $classEntries) {
            self::emitPropertyNotExists($context, 'object', $propName);

            return self::unreachableFalse($context);
        }
        $lastKey = array_key_last($classEntries);
        foreach ($classEntries as $classId => $className) {
            $thisClassLc = strtolower(ltrim($className, '\\'));
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $runtimeClassId,
                $i64->constInt((int) $classId, false)
            );
            $caseBlock = $fn->appendBasicBlock('prop_is_init_class_'.$classId);
            $nextBlock = $classId === $lastKey
                ? $fallback
                : $fn->appendBasicBlock('prop_is_init_try_'.$classId);
            $context->builder->branchIf($match, $caseBlock, $nextBlock);
            $context->builder->positionAtEnd($caseBlock);
            $resolved = $object->resolvePropertySlot($className, $propName);
            assert(null !== $resolved);
            [$declaringClassId, $slotIndex] = $resolved;
            $declaringClass = $object->classNameForId($declaringClassId);
            self::emitVisibilityGuard(
                $context,
                $object,
                $declaringClassId,
                $declaringClass,
                $propName,
                $thisClassLc
            );
            $init = self::emitSlotInitialized(
                $context,
                $object,
                $objPtr,
                $className,
                $propName,
                $declaringClassId,
                $slotIndex
            );
            $context->builder->store($init, $destSlot);
            $context->builder->branch($done);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($fallback);
        $context->builder->positionAtEnd($fallback);
        self::emitPropertyNotExistsRuntime($context, $objPtr, $propName);
        $context->builder->store(self::unreachableFalse($context), $destSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->branch($exit);
        $context->builder->positionAtEnd($exit);

        return $context->builder->load($destSlot);
    }

    private static function lowerRuntimeClassDynamicName(
        Context $context,
        Object_ $object,
        Value $objPtr,
        Variable $propNameArg
    ): Value {
        $runtimeName = JitStringArg::lowerPropertyName($context, $propNameArg);
        $map = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('prop_is_init_dyn_done');
        $exit = $fn->appendBasicBlock('prop_is_init_dyn_exit');
        $fallback = $fn->appendBasicBlock('prop_is_init_dyn_fallback');
        $destSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int1'));
        $i64 = $context->getTypeFromString('int64');
        $checkBlock = $entry;
        $classEntries = $object->allClassNamesById();
        $matched = false;
        foreach ($classEntries as $classId => $className) {
            foreach ($object->instancePropertySets((int) $classId) as $propset) {
                $propName = $propset[1];
                $context->builder->positionAtEnd($checkBlock);
                $classMatch = $context->builder->icmp(
                    Builder::INT_EQ,
                    $runtimeClassId,
                    $i64->constInt((int) $classId, false)
                );
                $litLoaded = $context->builder->load($context->constantStringFromString($propName));
                $nameMatch = JitStringCompare::identical($context, $runtimeName, $litLoaded);
                $both = $context->builder->and($classMatch, $nameMatch);
                $caseBlock = $fn->appendBasicBlock('prop_is_init_dyn_'.$classId.'_'.$propset[3]);
                $nextBlock = $fn->appendBasicBlock('prop_is_init_dyn_next_'.$classId.'_'.$propset[3]);
                $context->builder->branchIf($both, $caseBlock, $nextBlock);
                $context->builder->positionAtEnd($caseBlock);
                $declaringClassId = (int) $classId;
                $slotIndex = $propset[3];
                $declaringClass = $object->classNameForId($declaringClassId);
                self::emitVisibilityGuard(
                    $context,
                    $object,
                    $declaringClassId,
                    $declaringClass,
                    $propName,
                    strtolower(ltrim($className, '\\'))
                );
                $init = self::emitSlotInitialized(
                    $context,
                    $object,
                    $objPtr,
                    $className,
                    $propName,
                    $declaringClassId,
                    $slotIndex
                );
                $context->builder->store($init, $destSlot);
                $context->builder->branch($done);
                $checkBlock = $nextBlock;
                $matched = true;
            }
        }
        if (!$matched) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($fallback);
        $context->builder->positionAtEnd($fallback);
        self::emitPropertyNotExistsRuntime($context, $objPtr, 'property');
        $context->builder->store(self::unreachableFalse($context), $destSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->branch($exit);
        $context->builder->positionAtEnd($exit);

        return $context->builder->load($destSlot);
    }

    private static function emitSlotInitialized(
        Context $context,
        Object_ $object,
        Value $objPtr,
        string $receiverClass,
        string $propName,
        int $declaringClassId,
        int $slotIndex
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        if ($object->propertySlotHasCompileTimeDefault($declaringClassId, $slotIndex)) {
            return $i1->constInt(1, false);
        }
        $prop = $object->propertyFetch($objPtr, $receiverClass, $propName);
        if (Variable::TYPE_VALUE !== $prop->type) {
            return $i1->constInt(1, false);
        }
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($prop->value, $valueMap['type'])
        );
        self::ensureHelpers($context);

        return $context->builder->call(
            JitVmHelperLink::lookupCompiled($context, self::VALUE_BOX_HELPER, '#10186'),
            $typeByte
        );
    }

    private static function ensureHelpers(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10186'
        );
    }

    private static function emitVisibilityGuard(
        Context $context,
        Object_ $object,
        int $declaringClassId,
        string $declaringClass,
        string $propName,
        string $objectClassLc
    ): void {
        $vis = $object->propertyVisibility($declaringClassId, $propName);
        if (MethodVisibility::isPublic($vis)) {
            return;
        }
        $declaringLc = strtolower(ltrim($declaringClass, '\\'));
        $callerLc = self::callerClassLc($context);
        try {
            PropertyVisibility::assertAccessible(
                $vis,
                $callerLc,
                $declaringLc,
                $declaringClass,
                $propName,
                $objectClassLc,
                static fn (string $classLc, string $ancestorLc): bool => $object->classIsSubclassOf($classLc, $ancestorLc)
                    || $classLc === $ancestorLc
            );
        } catch (\LogicException $e) {
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            ErrorRaise::emitRaise($context, $e->getMessage());
            $context->builder->call($context->lookupFunction('abort'));
        }
    }

    private static function callerClassLc(Context $context): ?string
    {
        $block = $context->jitCurrentBlock;
        if (null !== $block?->func?->class) {
            return strtolower(ltrim($block->func->class->value, '\\'));
        }
        if ('' !== $context->scope->className) {
            return strtolower(ltrim($context->scope->className, '\\'));
        }

        return null;
    }

    private static function emitPropertyNotExists(Context $context, string $className, string $propName): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise(
            $context,
            sprintf('Property %s::$%s does not exist', $className, $propName)
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function emitPropertyNotExistsRuntime(
        Context $context,
        Value $objPtr,
        string $propName
    ): void {
        $object = $context->type->object;
        assert($object instanceof Object_);
        $map = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('prop_is_init_err_done');
        $checkBlock = $entry;
        $i64 = $context->getTypeFromString('int64');
        foreach ($object->allClassNamesById() as $classId => $className) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $runtimeClassId,
                $i64->constInt((int) $classId, false)
            );
            $raiseBlock = $fn->appendBasicBlock('prop_is_init_err_'.$classId);
            $nextBlock = $fn->appendBasicBlock('prop_is_init_err_next_'.$classId);
            $context->builder->branchIf($match, $raiseBlock, $nextBlock);
            $context->builder->positionAtEnd($raiseBlock);
            self::emitPropertyNotExists($context, $className, $propName);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($checkBlock);
        self::emitPropertyNotExists($context, 'object', $propName);
        $context->builder->positionAtEnd($done);
    }

    private static function unreachableFalse(Context $context): Value
    {
        return $context->getTypeFromString('int1')->constInt(0, false);
    }
}
