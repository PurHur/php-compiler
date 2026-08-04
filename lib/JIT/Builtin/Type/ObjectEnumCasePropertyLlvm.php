<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\EnumCasePropertyJitHelper;
use PHPLLVM;
use PHPLLVM\Value;

/**
 * LLVM lowering for enum case ->name/->value property fetch (#9938).
 *
 * isset()/?? on those pseudo-properties: {@see tryPropertyIsSet} (#27666, #9890).
 */
final class ObjectEnumCasePropertyLlvm
{
    /**
     * isset($case->name) / isset($case->value) — Zend propertyExistsOnCase (#9890, #27666).
     *
     * Returns null when $classId is not an enum and $name is not a case-sensitive
     * name/value probe (caller continues with ordinary hasProperty).
     */
    public static function tryPropertyIsSet(Object_ $object, Value $obj, int $classId, string $name): ?Value
    {
        $context = $object->jitContext();
        $i1 = $context->getTypeFromString('int1');
        if ($object->isEnumClassId($classId)) {
            // Case-sensitive like Zend / EnumCaseSupport::propertyExistsOnCase (#23532).
            if ('name' === $name) {
                return $i1->constInt(1, false);
            }
            if ('value' === $name) {
                return $i1->constInt($object->enumHasBacking($classId) ? 1 : 0, false);
            }

            return $i1->constInt(0, false);
        }
        // tryFrom()/from() results are often typed as generic object; resolve via class_id (#27666).
        if ('name' !== $name && 'value' !== $name) {
            return null;
        }
        $enumIds = $object->registeredEnumClassIds();
        if ([] === $enumIds) {
            return null;
        }

        return self::propertyIsSetEnumCaseRuntimeDispatch($object, $obj, $name, $enumIds);
    }

    /**
     * @param list<int> $enumIds
     */
    private static function propertyIsSetEnumCaseRuntimeDispatch(
        Object_ $object,
        Value $obj,
        string $name,
        array $enumIds
    ): Value {
        $context = $object->jitContext();
        $i1 = $context->getTypeFromString('int1');
        $dest = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(0, false), $dest);
        $map = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($obj, $map['class_id'])
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('enum_case_prop_isset_done');
        $i64 = $context->getTypeFromString('int64');
        $checkBlock = $entry;
        $lastIdx = \count($enumIds) - 1;
        foreach ($enumIds as $idx => $enumId) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $runtimeClassId,
                $i64->constInt($enumId, false)
            );
            $caseBlock = $fn->appendBasicBlock('enum_case_prop_isset_'.$enumId);
            $nextBlock = $idx === $lastIdx
                ? $done
                : $fn->appendBasicBlock('enum_case_prop_isset_try_'.($idx + 1));
            $context->builder->branchIf($match, $caseBlock, $nextBlock);
            $context->builder->positionAtEnd($caseBlock);
            $exists = ('name' === $name)
                || ('value' === $name && $object->enumHasBacking($enumId));
            $context->builder->store($i1->constInt($exists ? 1 : 0, false), $dest);
            $context->builder->branch($done);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($done);

        return $context->builder->load($dest);
    }

    public static function enumCasePropertyFetch(Object_ $object, Value $obj, int $classId, string $nameLc): Variable
    {
        $context = $object->jitContext();
        $slot = $object->enumCaseBuiltinPropertySlotPtr(
            $obj,
            EnumCasePropertyJitHelper::slotIndexForBuiltinProperty($nameLc)
        );
        $loaded = $context->builder->load($slot);
        if ('name' === $nameLc) {
            return new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $context->builder->pointerCast(
                    $loaded,
                    $context->getTypeFromString('__string__*')
                )
            );
        }
        $storage = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        $valueMap = $context->structFieldMap['__value__'];
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
            $context->builder->structGep($storage, $valueMap['type'])
        );
        $context->builder->call(
            $context->lookupFunction('__object__load_value_slot'),
            $slot,
            $storage
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $storage);
    }

    /**
     * @param list<int> $enumIds
     */
    public static function propertyFetchEnumCaseRuntimeDispatch(
        Object_ $object,
        Value $obj,
        string $nameLc,
        array $enumIds,
        string $fallbackClassName = 'stdClass',
        string $propertyName = ''
    ): Variable {
        $context = $object->jitContext();
        $map = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($obj, $map['class_id'])
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('enum_case_prop_fetch_done');
        $fallback = $fn->appendBasicBlock('enum_case_prop_fetch_fallback');
        // Always box into __value__ so non-enum fallback can be real NULL (#27666).
        $destSlot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $destSlot);
        $i64 = $context->getTypeFromString('int64');
        $checkBlock = $entry;
        $lastIdx = \count($enumIds) - 1;
        foreach ($enumIds as $idx => $enumId) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $runtimeClassId,
                $i64->constInt($enumId, false)
            );
            $caseBlock = $fn->appendBasicBlock('enum_case_prop_fetch_'.$enumId);
            $nextBlock = $idx === $lastIdx
                ? $fallback
                : $fn->appendBasicBlock('enum_case_prop_fetch_try_'.($idx + 1));
            $context->builder->branchIf($match, $caseBlock, $nextBlock);
            $context->builder->positionAtEnd($caseBlock);
            $fetched = self::enumCasePropertyFetch($object, $obj, $enumId, $nameLc);
            if ('name' === $nameLc) {
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $destPtr,
                    $context->helper->loadValue($fetched)
                );
            } else {
                JitValueBox::copyFromPointer(
                    $context,
                    $destSlot,
                    JitValueBox::valuePtrFromVariable($context, $fetched)
                );
            }
            $context->builder->branch($done);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($fallback);
        // Non-enum receiver (e.g. stdClass in a TU that also defines enums): warn + null (#27666).
        $warnClass = '' !== $fallbackClassName ? $fallbackClassName : 'stdClass';
        $warnProp = '' !== $propertyName ? $propertyName : $nameLc;
        \PHPCompiler\JIT\Builtin\UndefinedPropertyFetchRuntime::emitWarning(
            $context,
            $warnClass,
            $warnProp
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $destSlot
        );
    }

    public static function enumCaseBackingLong(Object_ $object, Context $context, Value $objPtr): Value
    {
        $slot = $object->enumCaseBuiltinPropertySlotPtr(
            $objPtr,
            EnumCasePropertyJitHelper::SLOT_VALUE
        );
        $storage = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        $valueMap = $context->structFieldMap['__value__'];
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
            $context->builder->structGep($storage, $valueMap['type'])
        );
        $context->builder->call(
            $context->lookupFunction('__object__load_value_slot'),
            $slot,
            $storage
        );

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $storage
        );
    }
}
