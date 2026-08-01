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
 * LLVM lowering helpers for ?-> nullsafe branch targets (issues #308, #3219, #10154, #10311, #26364).
 *
 * SSOT: {@see \PHPCompiler\VM\TypedPropertyCheck}, {@see \PHPCompiler\VM\NullsafeJitHelper}
 */
final class NullsafeHelper
{
    private const HELPER_PATH = '/VM/NullsafeJitHelper.php';

    private const VALUE_BOX_HELPER = 'PHPCompiler\\VM\\NullsafeJitHelper::valueBoxShortCircuits';

    private const VALUE_BOX_METHOD_HELPER = 'PHPCompiler\\VM\\NullsafeJitHelper::valueBoxMethodShortCircuits';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE_BOX_HELPER,
        self::VALUE_BOX_METHOD_HELPER,
    ];

    public static function compileBranch(
        JIT $jit,
        Function_ $func,
        Block $branchBlock
    ): BasicBlock {
        $saved = $branchBlock->syntheticCfgBranch ?? false;
        $branchBlock->syntheticCfgBranch = true;
        try {
            return $jit->compileSubBlock($func, $branchBlock);
        } finally {
            $branchBlock->syntheticCfgBranch = $saved;
        }
    }

    /**
     * i1: receiver short-circuits ?-> — null / uninitialized nullable only (#26365, #26364).
     *
     * Known non-null non-objects do not short-circuit: property fetch warns (#26365);
     * METHODCALL_INIT Errors (#26364).
     */
    public static function isReceiverNull(
        JIT $jit,
        Variable $receiver,
        bool $forMethodCall = false
    ): Value {
        $context = $jit->context;
        $builder = $context->builder;
        $i1 = $context->getTypeFromString('int1');
        if (Variable::TYPE_NULL === $receiver->type) {
            return $i1->constInt(1, false);
        }
        // Known non-null non-object: fall through to fetch/call arm (#26365 / #26364).
        if (self::receiverIsKnownNonNullNonObject($receiver->type)) {
            return $i1->constInt(0, false);
        }
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
        $nullableSlot = $i1->constInt(self::nullablePropertySlot($context, $receiver) ? 1 : 0, false);

        return self::callValueBoxShortCircuits($context, $typeByte, $nullableSlot, $forMethodCall);
    }

    private static function receiverIsKnownNonNullNonObject(int $jitType): bool
    {
        return \in_array($jitType, [
            Variable::TYPE_NATIVE_LONG,
            Variable::TYPE_NATIVE_BOOL,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::TYPE_STRING,
            Variable::TYPE_HASHTABLE,
        ], true);
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
        self::ensureValueBoxBridge(
            $context,
            '__nullsafe__valueBoxShortCircuits',
            'nullsafe_value_box_bridge_entry',
            self::VALUE_BOX_HELPER
        );
        self::ensureValueBoxBridge(
            $context,
            '__nullsafe__valueBoxMethodShortCircuits',
            'nullsafe_value_box_method_bridge_entry',
            self::VALUE_BOX_METHOD_HELPER
        );
    }

    private static function ensureValueBoxBridge(
        Context $context,
        string $abiName,
        string $bridgeEntry,
        string $helperFqcn
    ): void {
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
            $bridgeEntry,
            [$i8, $i1],
            $i1,
            $helperFqcn,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10311'
        );
    }

    private static function callValueBoxShortCircuits(
        Context $context,
        Value $typeByte,
        Value $nullableSlot,
        bool $forMethodCall
    ): Value {
        self::ensureLinked($context);
        $abiName = $forMethodCall
            ? '__nullsafe__valueBoxMethodShortCircuits'
            : '__nullsafe__valueBoxShortCircuits';
        $fn = $context->lookupFunction($abiName);
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8),
            $context->builder->trunc($nullableSlot, $i1)
        );
    }
}
