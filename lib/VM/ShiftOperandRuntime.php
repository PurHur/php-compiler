<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT runtime bridge for shift operand validation on boxed operands (#30138).
 *
 * SSOT: {@see ShiftOperandJitHelper}, {@see Variable::validateShiftOperands}
 */
final class ShiftOperandRuntime
{
    private const ABI_GUARD = '__shift_op__guardValueBoxPair';

    private const HELPER_PATH = '/lib/VM/ShiftOperandJitHelper.php';

    private const GUARD_HELPER = 'PHPCompiler\\VM\\ShiftOperandJitHelper::guardShiftValueBoxPair';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GUARD_HELPER,
    ];

    public static function guardRuntimeOperands(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): void {
        if (!self::needsRuntimeBridge($left) && !self::needsRuntimeBridge($right)) {
            return;
        }
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_GUARD);
        $i32 = $context->getTypeFromString('int32');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $leftPtr = self::operandValuePtr($context, $left, $valuePtrTy);
        $rightPtr = self::operandValuePtr($context, $right, $valuePtrTy);
        if (null === $leftPtr || null === $rightPtr) {
            return;
        }
        $context->builder->call(
            $fn,
            $i32->constInt($opCode, false),
            $leftPtr,
            $rightPtr
        );
    }

    private static function needsRuntimeBridge(Variable $operand): bool
    {
        return Variable::TYPE_VALUE === $operand->type && JitValueBox::isValueOperand($operand);
    }

    private static function operandValuePtr(Context $context, Variable $operand, $valuePtrTy): ?Value
    {
        if (Variable::TYPE_VALUE === $operand->type && JitValueBox::isValueOperand($operand)) {
            return JitValueBox::valuePtrFromVariable($context, $operand);
        }

        return null;
    }

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_GUARD);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_GUARD, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_GUARD,
            'shift_op_guard_vbox_bridge_entry',
            [$i32, $valuePtr, $valuePtr],
            $voidTy,
            self::GUARD_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30138'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Runtime: reject object or non-numeric string value boxes before inline shift lowering.
     */
    public static function emitValueBoxObjectReject(
        Context $context,
        int $opCode,
        Variable $operand,
        Variable $other
    ): void {
        if (Variable::TYPE_VALUE !== $operand->type || !JitValueBox::isValueOperand($operand)) {
            return;
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $operand);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $objectTy = $i8->constInt(\PHPCompiler\VM\Variable::TYPE_OBJECT, false);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $rejectBlock = BasicBlockHelper::append($context, 'shift_vbox_object_reject');
        $continueBlock = BasicBlockHelper::append($context, 'shift_vbox_object_cont');
        $context->builder->branchIf($isObject, $rejectBlock, $continueBlock);
        $context->builder->positionAtEnd($rejectBlock);
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_GUARD);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $fn,
            $i32->constInt($opCode, false),
            $valuePtr,
            $valuePtr
        );
        $context->builder->positionAtEnd($continueBlock);
    }
}
