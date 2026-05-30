<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\Frame;
use PHPCompiler\OpCode;

/**
 * Evaluate scalar class constant expressions at class-compile time (#3567).
 *
 * Reference: Zend/zend_compile.c — zend_compile_const_expr(), zend_const_expr_to_zval()
 */
final class ClassConstExpr
{
    public static function isSupportedOpcode(int $type): bool
    {
        return match ($type) {
            OpCode::TYPE_PLUS,
            OpCode::TYPE_MINUS,
            OpCode::TYPE_MUL,
            OpCode::TYPE_DIV,
            OpCode::TYPE_MODULO,
            OpCode::TYPE_POW,
            OpCode::TYPE_BITWISE_AND,
            OpCode::TYPE_BITWISE_OR,
            OpCode::TYPE_BITWISE_XOR,
            OpCode::TYPE_SHIFT_LEFT,
            OpCode::TYPE_SHIFT_RIGHT,
            OpCode::TYPE_UNARY_MINUS,
            OpCode::TYPE_UNARY_PLUS,
            OpCode::TYPE_BITWISE_NOT,
            OpCode::TYPE_BOOLEAN_NOT,
            OpCode::TYPE_CONCAT,
            OpCode::TYPE_CONST_FETCH,
            OpCode::TYPE_CLASS_CONST_FETCH => true,
            default => false,
        };
    }

    public static function execute(Context $context, Frame $frame, OpCode $op, ClassEntry $entry): void
    {
        switch ($op->type) {
            case OpCode::TYPE_PLUS:
            case OpCode::TYPE_MINUS:
            case OpCode::TYPE_MUL:
            case OpCode::TYPE_DIV:
            case OpCode::TYPE_MODULO:
            case OpCode::TYPE_POW:
                $frame->scope[$op->arg1]->numericOp(
                    $op->type,
                    $frame->scope[$op->arg2],
                    $frame->scope[$op->arg3]
                );
                break;
            case OpCode::TYPE_BITWISE_AND:
            case OpCode::TYPE_BITWISE_OR:
            case OpCode::TYPE_BITWISE_XOR:
            case OpCode::TYPE_SHIFT_LEFT:
            case OpCode::TYPE_SHIFT_RIGHT:
                $frame->scope[$op->arg1]->bitwiseOp(
                    $op->type,
                    $frame->scope[$op->arg2],
                    $frame->scope[$op->arg3]
                );
                break;
            case OpCode::TYPE_UNARY_MINUS:
            case OpCode::TYPE_UNARY_PLUS:
            case OpCode::TYPE_BITWISE_NOT:
            case OpCode::TYPE_BOOLEAN_NOT:
                $frame->scope[$op->arg1]->unaryOp($op->type, $frame->scope[$op->arg2]);
                break;
            case OpCode::TYPE_CONCAT:
                $frame->scope[$op->arg1]->string(
                    $frame->scope[$op->arg2]->toString()
                    . $frame->scope[$op->arg3]->toString()
                );
                break;
            case OpCode::TYPE_CONST_FETCH:
                self::executeConstFetch($context, $frame, $op);
                break;
            case OpCode::TYPE_CLASS_CONST_FETCH:
                self::executeClassConstFetch($context, $frame, $op, $entry);
                break;
            default:
                throw new \LogicException(
                    'Unsupported class const expression opcode: '.opcode_type_name($op->type)
                );
        }
    }

    public static function resolveValue(Frame $frame, Block $block, int $slot): Variable
    {
        if (isset($block->constants[$slot])) {
            $value = new Variable();
            $value->copyFrom($block->constants[$slot]);

            return $value;
        }
        if (!isset($frame->scope[$slot])) {
            throw new \LogicException('Class constant value must be a compile-time constant');
        }
        $value = new Variable();
        $value->copyFrom($frame->scope[$slot]);

        return $value;
    }

    private static function executeConstFetch(Context $context, Frame $frame, OpCode $op): void
    {
        $value = null;
        if (null !== $op->arg3) {
            $value = $context->constantFetch($frame->scope[$op->arg3]->toString());
        }
        if (null === $value) {
            $value = $context->constantFetch($frame->scope[$op->arg2]->toString());
        }
        if (null === $value) {
            throw new \LogicException('Unknown constant fetch');
        }
        $frame->scope[$op->arg1]->copyFrom($value);
    }

    private static function executeClassConstFetch(
        Context $context,
        Frame $frame,
        OpCode $op,
        ClassEntry $entry
    ): void {
        $className = $frame->scope[$op->arg2]->toString();
        $lcClass = self::resolveClassName($context, $entry, $className);
        $constName = strtolower($frame->scope[$op->arg3]->toString());

        if ($lcClass === strtolower($entry->name)) {
            self::fetchFromDeclaringClass($frame, $op, $entry, $constName);

            return;
        }

        if (!isset($context->classes[$lcClass])) {
            if ('self' !== strtolower($className) && 'static' !== strtolower($className)) {
                $context->autoloadClass($className);
            }
        }
        if (!isset($context->classes[$lcClass])) {
            throw new \LogicException("Unknown class for constant fetch: {$className}");
        }

        $classEntry = $context->classes[$lcClass];
        if ('class' === $constName) {
            $frame->scope[$op->arg1]->string($classEntry->name);

            return;
        }
        if (!isset($classEntry->constants[$constName])) {
            throw new \LogicException("Undefined class constant {$className}::{$constName}");
        }
        if ($classEntry->isEnum) {
            $canonical = $classEntry->enumCaseCanonicalNames[$constName]
                ?? $frame->scope[$op->arg3]->toString();
            $backing = new Variable();
            $backing->copyFrom($classEntry->constants[$constName]);
            $frame->scope[$op->arg1]->enumCase(
                new EnumCaseEntry($classEntry, $canonical, $backing)
            );

            return;
        }
        $frame->scope[$op->arg1]->copyFrom($classEntry->constants[$constName]);
    }

    private static function fetchFromDeclaringClass(
        Frame $frame,
        OpCode $op,
        ClassEntry $entry,
        string $constName
    ): void {
        if ('class' === $constName) {
            $frame->scope[$op->arg1]->string($entry->name);

            return;
        }
        if (!isset($entry->constants[$constName])) {
            throw new \LogicException(
                "Undefined class constant {$entry->name}::{$constName}"
            );
        }
        $frame->scope[$op->arg1]->copyFrom($entry->constants[$constName]);
    }

    private static function resolveClassName(Context $context, ClassEntry $entry, string $className): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass || $lcClass === strtolower($entry->name)) {
            return strtolower($entry->name);
        }
        if ('parent' === $lcClass) {
            if (null === $entry->parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $entry->parentLc;
        }
        if ('static' === $lcClass) {
            return strtolower($entry->name);
        }

        return $lcClass;
    }
}
