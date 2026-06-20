<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin\Type\Object_;

/**
 * LLVM lowering helpers for ?-> nullsafe branch targets (issues #308, #3219, #10154, #10311).
 *
 * SSOT: {@see \PHPCompiler\VM\TypedPropertyCheck}, {@see \PHPCompiler\VM\NullsafeJitHelper}
 */
final class NullsafeHelper
{
    private const HELPER_PATH = '/VM/NullsafeJitHelper.php';

    private const VALUE_BOX_HELPER = 'PHPCompiler\\VM\\NullsafeJitHelper::valueBoxShortCircuits';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE_BOX_HELPER,
    ];

    public static function compileBranch(
        JIT $jit,
        Function_ $func,
        Block $branchBlock
    ): BasicBlock {
        return $jit->compileSubBlock($func, $branchBlock);
    }

    /**
     * i1: receiver is PHP null or uninitialized nullable typed property (#5220, ZEND_NULLSAFE).
     */
    public static function isReceiverNull(JIT $jit, Variable $receiver): Value
    {
        $context = $jit->context;
        $builder = $context->builder;
        if (Variable::TYPE_OBJECT === $receiver->type) {
            $obj = $context->helper->loadValue($receiver);

            return $builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $obj,
                $obj->typeOf()->constNull()
            );
        }
        if (Variable::TYPE_VALUE !== $receiver->type) {
            throw new \LogicException('nullsafe receiver must be object or value box');
        }
        self::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $receiver);
        $typeByte = $builder->load(
            $builder->structGep(
                $valuePtr,
                $context->structFieldMap['__value__']['type']
            )
        );
        $i1 = $context->getTypeFromString('int1');
        $nullableSlot = $i1->constInt(self::nullablePropertySlot($context, $receiver) ? 1 : 0, false);

        return self::callValueBoxShortCircuits($context, $typeByte, $nullableSlot);
    }

    private static function nullablePropertySlot(Context $context, Variable $receiver): bool
    {
        if (null === $receiver->objectPropertyClassName || null === $receiver->objectPropertyName) {
            return false;
        }
        $object = $context->type->object;
        if (!$object instanceof Object_) {
            return false;
        }
        $resolved = $object->resolvePropertySlot($receiver->objectPropertyClassName, $receiver->objectPropertyName);
        if (null === $resolved) {
            return false;
        }
        [$classId, $slotIndex] = $resolved;

        return $object->propertySlotAllowsNull($classId, $slotIndex);
    }

    private static function ensureLinked(Context $context): void
    {
        $abiName = '__nullsafe__valueBoxShortCircuits';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            $abiName,
            'nullsafe_value_box_bridge_entry',
            [$i8, $i1],
            $i1,
            self::VALUE_BOX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10311'
        );
        $context->builder->clearInsertionPosition();
    }

    private static function callValueBoxShortCircuits(Context $context, Value $typeByte, Value $nullableSlot): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__nullsafe__valueBoxShortCircuits');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8),
            $context->builder->trunc($nullableSlot, $i1)
        );
    }
}
