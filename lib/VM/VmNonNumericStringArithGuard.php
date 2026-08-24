<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueNumeric;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * SSOT for JIT/AOT non-numeric string ⊙ arithmetic TypeErrors (#34449).
 *
 * php-src: Zend/zend_operators.c convert_scalar_to_number — strings with no
 * numeric prefix throw zend_type_error; leading junk ("5x") warns and coerces.
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitNonNumericStringArithGuard}
 */
final class VmNonNumericStringArithGuard
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
        if (!JitValueNumeric::isArithOpcode($opCode)) {
            return false;
        }

        $leftCompileBad = self::isCompileTimeNonNumericString($left);
        $rightCompileBad = self::isCompileTimeNonNumericString($right);
        if ($leftCompileBad || $rightCompileBad) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                sprintf(
                    'Unsupported operand types: %s %s %s',
                    JitOperandTypeLabel::givenLabel($context, $left),
                    self::operatorSymbol($opCode),
                    JitOperandTypeLabel::givenLabel($context, $right)
                )
            );

            return true;
        }

        // Runtime TYPE_STRING without a proven numeric prefix: strtod must consume ≥1 char.
        if (Variable::TYPE_STRING === $left->type && null === $left->compileTimeString) {
            self::emitRuntimeNumericPrefixGuard(
                $context,
                $left,
                sprintf(
                    'Unsupported operand types: %s %s %s',
                    JitOperandTypeLabel::givenLabel($context, $left),
                    self::operatorSymbol($opCode),
                    JitOperandTypeLabel::givenLabel($context, $right)
                ),
                'nnstr_left'
            );
        }
        if (Variable::TYPE_STRING === $right->type && null === $right->compileTimeString) {
            self::emitRuntimeNumericPrefixGuard(
                $context,
                $right,
                sprintf(
                    'Unsupported operand types: %s %s %s',
                    JitOperandTypeLabel::givenLabel($context, $left),
                    self::operatorSymbol($opCode),
                    JitOperandTypeLabel::givenLabel($context, $right)
                ),
                'nnstr_right'
            );
        }

        return false;
    }

    private static function isCompileTimeNonNumericString(Variable $var): bool
    {
        if (Variable::TYPE_STRING !== $var->type) {
            return false;
        }
        $literal = $var->compileTimeString;
        if (null === $literal) {
            return false;
        }

        return VmVariable::isArithmeticNonNumericString($literal);
    }

    /**
     * strtod must advance past the start (numeric prefix). Full-string consume is not required
     * ("5x" warns+coerces in Zend). Empty / alpha-only → TypeError.
     */
    private static function emitRuntimeNumericPrefixGuard(
        Context $context,
        Variable $stringVar,
        string $message,
        string $tag
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, $tag.'_cont');
        $strPtr = $context->helper->loadValue($stringVar);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, $tag.'_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        LibcExtern::ensureStrtodDecl($context);
        $context->builder->call(
            $context->lookupFunction('strtod'),
            $charPtr,
            $endPtrSlot
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $consumedNothing = $context->builder->icmp(Builder::INT_EQ, $endPtr, $charPtr);

        $fail = BasicBlockHelper::append($context, $tag.'_fail');
        $ok = BasicBlockHelper::append($context, $tag.'_ok');
        $context->builder->branchIf($consumedNothing, $fail, $ok);

        $context->builder->positionAtEnd($fail);
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        BasicBlockHelper::ensureOpenInsertBlock($context, $tag.'_fail_cont');
        $context->builder->branch($ok);

        $context->builder->positionAtEnd($ok);
    }

    private static function operatorSymbol(int $opCode): string
    {
        return match ($opCode) {
            OpCode::TYPE_PLUS => '+',
            OpCode::TYPE_MINUS => '-',
            OpCode::TYPE_MUL => '*',
            OpCode::TYPE_DIV => '/',
            default => '?',
        };
    }
}
