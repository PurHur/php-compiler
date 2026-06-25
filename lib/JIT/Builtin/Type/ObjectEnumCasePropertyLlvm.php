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
 */
final class ObjectEnumCasePropertyLlvm
{
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
        array $enumIds
    ): Variable {
        $context = $object->jitContext();
        $map = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($obj, $map['class_id'])
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('enum_case_prop_fetch_done');
        $exit = $fn->appendBasicBlock('enum_case_prop_fetch_exit');
        $fallback = $fn->appendBasicBlock('enum_case_prop_fetch_fallback');
        $isName = 'name' === $nameLc;
        if ($isName) {
            $destSlot = BasicBlockHelper::entryAlloca(
                $context,
                $context->getTypeFromString('__string__*')
            );
        } else {
            $destSlot = JitValueBox::alloc($context);
        }
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
            if ($isName) {
                $context->builder->store(
                    $context->helper->loadValue($fetched),
                    $destSlot
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
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($done);
        $context->builder->branch($exit);
        $context->builder->positionAtEnd($exit);
        if ($isName) {
            return new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $context->builder->load($destSlot)
            );
        }

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $destSlot
        );
    }
}
