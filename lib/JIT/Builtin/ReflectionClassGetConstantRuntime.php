<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ClassConstName;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionClass::getConstant() for literal names (#34093).
 *
 * Runtime class id (from ReflectionClass name) + compile-time constant name →
 * {@see Type\Object_::classConstFetch} value, or bool false when missing
 * (php-src zim_ReflectionClass_getConstant — not a LogicException).
 *
 * Peer: {@see ClassConstFetchHelper::fetchLiteralConstWithRuntimeClass} (throws on miss).
 */
final class ReflectionClassGetConstantRuntime
{
    /**
     * @return Value __value__* result slot
     */
    public static function emitForLiteralName(
        Context $context,
        Value $classIdVal,
        string $constName
    ): Value {
        $object = $context->type->object;
        $key = ClassConstName::key($constName);
        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock('refl_getconst_merge');
        $miss = $fn->appendBasicBlock('refl_getconst_miss');

        $checkBlock = $entry;
        $idx = 0;
        foreach ($object->allClassNamesById() as $id => $_) {
            $id = (int) $id;
            $holdingId = $object->resolveClassConstHoldingId($id, $key);
            if (null === $holdingId) {
                continue;
            }
            $declared = $object->classConstDeclaredNameOrNull($holdingId, $key);
            if (!ClassConstName::matchesDeclared($constName, $declared)) {
                continue;
            }

            $matchBlock = $fn->appendBasicBlock('refl_getconst_hit_'.$idx);
            $nextCheck = $fn->appendBasicBlock('refl_getconst_try_'.$idx);
            $context->builder->positionAtEnd($checkBlock);
            $expectedId = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classIdVal, $expectedId);
            $context->builder->branchIf($isId, $matchBlock, $nextCheck);

            $context->builder->positionAtEnd($matchBlock);
            $display = $object->classNameForId($id);
            // classConstFetch walks parents / visibility (same as C::CONST).
            $jit = $object->classConstFetch($id, $constName, null, $display);
            JitValueBox::assignToPointer(
                $context,
                JitValueBox::pointer($context, $resultSlot),
                $jit
            );
            $context->builder->branch($merge);

            $checkBlock = $nextCheck;
            ++$idx;
        }

        // Class id matched a known class without this constant, or unknown id → false.
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($miss);
        JitValueBox::writeBool(
            $context,
            $resultSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }
}
