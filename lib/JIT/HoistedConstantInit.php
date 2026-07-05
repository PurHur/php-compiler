<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Seed hoisted CFG operands from compile-time {@see Block::$constants} (#16378, #2215).
 *
 * When the compiler folds ClassConstFetch (or similar) into a slot constant, php-cfg may
 * elide the fetch opcode; JIT must not leave the hoisted temporary null-initialized.
 */
final class HoistedConstantInit
{
    public static function tryVariableFromBlockConstant(Context $context, Block $block, Operand $op): ?Variable
    {
        $slot = $block->slotForOperand($op);
        if (null === $slot || !isset($block->constants[$slot])) {
            return null;
        }
        $vm = $block->constants[$slot];
        if ($vm->is(VMVariable::TYPE_NULL)) {
            return null;
        }

        return self::variableFromVmConstant($context, $vm, $op);
    }

    public static function variableFromVmConstant(Context $context, VMVariable $vm, Operand $op): Variable
    {
        switch ($vm->type) {
            case VMVariable::TYPE_INTEGER:
                return Variable::fromConstantInt($context, $vm->toInt());
            case VMVariable::TYPE_STRING:
                $lit = new Operand\Literal($vm->toString());
                $lit->type = Type::string();

                return Variable::fromLiteral($context, $lit);
            case VMVariable::TYPE_FLOAT:
                $lit = new Operand\Literal($vm->toFloat());
                $lit->type = Type::float();

                return Variable::fromLiteral($context, $lit);
            case VMVariable::TYPE_BOOLEAN:
                $lit = new Operand\Literal($vm->toBool());
                $lit->type = Type::bool();

                return Variable::fromLiteral($context, $lit);
            case VMVariable::TYPE_NULL:
                $nullVar = new Variable(
                    $context,
                    Variable::TYPE_NULL,
                    Variable::KIND_VALUE,
                    $context->getTypeFromString('__value__*')->constNull()
                );
                $nullVar->isNullConstant = true;

                return $nullVar;
            case VMVariable::TYPE_ARRAY:
                return HashTableHelper::variableFromVmHashTable($context, $vm->toArray());
            default:
                throw new \LogicException(
                    'Unsupported hoisted compile-time constant type for JIT: '.$vm->type
                );
        }
    }
}
