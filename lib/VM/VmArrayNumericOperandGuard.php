<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;

/**
 * SSOT for JIT/AOT array ⊙ non-array arithmetic TypeErrors (#32346).
 *
 * php-src: Zend/zend_operators.c add_function — IS_ARRAY+IS_ARRAY unions;
 * mixed array ⊙ scalar is zend_type_error. sub/mul/div/mod/pow likewise.
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitArrayNumericOperandGuard}
 */
final class VmArrayNumericOperandGuard
{
    /**
     * @return bool true when TypeError+abort was emitted (caller must not continue lowering)
     */
    public static function guardArithmetic(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): bool {
        if (!self::isGuardedArithmeticOp($opCode)) {
            return false;
        }
        $leftArr = self::isArrayOperand($left);
        $rightArr = self::isArrayOperand($right);
        if (!$leftArr && !$rightArr) {
            return false;
        }
        // add_function: array + array is union, not TypeError.
        if ($leftArr && $rightArr && OpCode::TYPE_PLUS === $opCode) {
            return false;
        }
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            sprintf(
                'Unsupported operand types: %s %s %s',
                self::typeLabel($context, $left),
                self::operatorSymbol($opCode),
                self::typeLabel($context, $right)
            )
        );

        return true;
    }

    private static function isGuardedArithmeticOp(int $opCode): bool
    {
        return OpCode::TYPE_PLUS === $opCode
            || OpCode::TYPE_MINUS === $opCode
            || OpCode::TYPE_MUL === $opCode
            || OpCode::TYPE_DIV === $opCode
            || OpCode::TYPE_MODULO === $opCode
            || OpCode::TYPE_POW === $opCode;
    }

    private static function isArrayOperand(Variable $var): bool
    {
        return Variable::TYPE_HASHTABLE === $var->type
            || ArrayBuiltinHelper::isNativeArray($var->type);
    }

    private static function typeLabel(Context $context, Variable $var): string
    {
        if (self::isArrayOperand($var)) {
            return 'array';
        }

        return JitOperandTypeLabel::givenLabel($context, $var);
    }

    private static function operatorSymbol(int $opCode): string
    {
        return match ($opCode) {
            OpCode::TYPE_PLUS => '+',
            OpCode::TYPE_MINUS => '-',
            OpCode::TYPE_MUL => '*',
            OpCode::TYPE_DIV => '/',
            OpCode::TYPE_MODULO => '%',
            OpCode::TYPE_POW => '**',
            default => '?',
        };
    }
}
