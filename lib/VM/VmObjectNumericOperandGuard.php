<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as JitObjectType;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * SSOT for JIT/AOT object ⊙ scalar arithmetic/bitwise TypeErrors (#32477, #32486).
 *
 * php-src: Zend/zend_operators.c — objects without numeric do_operation
 * (GMP / BcMath\Number) throw zend_type_error. Unary +/− messages are
 * {@code Unsupported operand types: stdClass} (operator_unsupported_types.phpt).
 * Unary {@code ~} is {@code Cannot perform bitwise not on stdClass}.
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitObjectNumericOperandGuard}
 */
final class VmObjectNumericOperandGuard
{
    /**
     * @return bool true when the arithmetic op must not continue native lowering
     */
    public static function guardArithmetic(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): bool {
        if (NestedJitCompileScope::isActive()) {
            return false;
        }
        if (!self::isGuardedArithmeticOp($opCode)) {
            return false;
        }
        $leftObj = Variable::TYPE_OBJECT === $left->type;
        $rightObj = Variable::TYPE_OBJECT === $right->type;
        if ($leftObj || $rightObj) {
            $op = self::operatorSymbol($opCode);
            if ($leftObj && !$rightObj) {
                self::emitNativeObjectTypeError(
                    $context,
                    $left,
                    static fn (string $class): string => sprintf(
                        'Unsupported operand types: %s %s %s',
                        $class,
                        $op,
                        JitOperandTypeLabel::givenLabel($context, $right)
                    )
                );

                return true;
            }
            if ($rightObj && !$leftObj) {
                self::emitNativeObjectTypeError(
                    $context,
                    $right,
                    static fn (string $class): string => sprintf(
                        'Unsupported operand types: %s %s %s',
                        JitOperandTypeLabel::givenLabel($context, $left),
                        $op,
                        $class
                    )
                );

                return true;
            }
            self::emitNativeObjectTypeError(
                $context,
                $left,
                static fn (string $class): string => sprintf(
                    'Unsupported operand types: %s %s %s',
                    $class,
                    $op,
                    JitOperandTypeLabel::givenLabel($context, $right)
                )
            );

            return true;
        }
        if (Variable::TYPE_VALUE === $left->type && JitValueBox::isValueOperand($left)) {
            self::emitBoxedObjectTypeError(
                $context,
                $left,
                static fn (string $class): string => sprintf(
                    'Unsupported operand types: %s %s %s',
                    $class,
                    self::operatorSymbol($opCode),
                    JitOperandTypeLabel::givenLabel($context, $right)
                )
            );
        }
        if (Variable::TYPE_VALUE === $right->type && JitValueBox::isValueOperand($right)) {
            self::emitBoxedObjectTypeError(
                $context,
                $right,
                static fn (string $class): string => sprintf(
                    'Unsupported operand types: %s %s %s',
                    JitOperandTypeLabel::givenLabel($context, $left),
                    self::operatorSymbol($opCode),
                    $class
                )
            );
        }

        return false;
    }

    /**
     * Unary +/− on a native or boxed object without do_operation (#32477).
     *
     * @return bool true when native TYPE_OBJECT was fully handled (caller must not continue)
     */
    public static function guardUnary(Context $context, Variable $var): bool
    {
        if (NestedJitCompileScope::isActive()) {
            return false;
        }
        $msg = static fn (string $class): string => 'Unsupported operand types: '.$class;
        if (Variable::TYPE_OBJECT === $var->type) {
            self::emitNativeObjectTypeError($context, $var, $msg);

            return true;
        }
        if (Variable::TYPE_VALUE === $var->type && JitValueBox::isValueOperand($var)) {
            self::emitBoxedObjectTypeError($context, $var, $msg);
        }

        return false;
    }

    /**
     * Unary {@code ~} on a native or boxed object without do_operation (#32486).
     *
     * php-src: Zend/zend_operators.c bitwise_not_function —
     * {@code Cannot perform bitwise not on %s}.
     *
     * @return bool true when native TYPE_OBJECT was fully handled (caller must not continue)
     */
    public static function guardUnaryBitwiseNot(Context $context, Variable $var): bool
    {
        if (NestedJitCompileScope::isActive()) {
            return false;
        }
        $msg = static fn (string $class): string => 'Cannot perform bitwise not on '.$class;
        if (Variable::TYPE_OBJECT === $var->type) {
            self::emitNativeObjectTypeError($context, $var, $msg);

            return true;
        }
        if (Variable::TYPE_VALUE === $var->type && JitValueBox::isValueOperand($var)) {
            self::emitBoxedObjectTypeError($context, $var, $msg);
        }

        return false;
    }

    /**
     * @param callable(string):string $messageForClass
     */
    private static function emitNativeObjectTypeError(
        Context $context,
        Variable $var,
        callable $messageForClass
    ): void {
        $objPtr = $context->helper->loadValue($var);
        self::emitClassIdTypeErrorSwitch($context, $objPtr, $messageForClass);
    }

    /**
     * Runtime: value-box IS_OBJECT → TypeError; otherwise continue lowering.
     *
     * @param callable(string):string $messageForClass
     */
    private static function emitBoxedObjectTypeError(
        Context $context,
        Variable $var,
        callable $messageForClass
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'obj_num_vbox_cont');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $mask = $i8->constInt(0x7f, false);
        $kind = $context->builder->and($typeByte, $mask);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        $rejectBlock = BasicBlockHelper::append($context, 'obj_num_vbox_reject');
        $continueBlock = BasicBlockHelper::append($context, 'obj_num_vbox_ok');
        $context->builder->branchIf($isObject, $rejectBlock, $continueBlock);
        $context->builder->positionAtEnd($rejectBlock);
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        self::emitClassIdTypeErrorSwitch($context, $objPtr, $messageForClass);
        $context->builder->positionAtEnd($continueBlock);
    }

    /**
     * @param callable(string):string $messageForClass
     */
    private static function emitClassIdTypeErrorSwitch(
        Context $context,
        \PHPLLVM\Value $objPtr,
        callable $messageForClass
    ): void {
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            ExceptionBridge::emitTypeErrorAndAbort($context, $messageForClass('object'));

            return;
        }
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        $entries = [];
        $overloadedIds = [];
        foreach ($jitObject->allClassNamesById() as $id => $name) {
            $lc = strtolower(ltrim((string) $name, '\\'));
            if ('gmp' === $lc || 'bcmath\\number' === $lc) {
                $overloadedIds[] = (int) $id;
                continue;
            }
            $entries[(int) $id] = (string) $name;
        }
        $okBlock = BasicBlockHelper::append($context, 'obj_num_class_ok');
        $fallbackBlock = BasicBlockHelper::append($context, 'obj_num_class_fallback');
        $i64 = $context->getTypeFromString('int64');
        $dispatch = [];
        foreach ($overloadedIds as $id) {
            $dispatch[$id] = 'ok';
        }
        foreach ($entries as $id => $name) {
            $dispatch[$id] = $name;
        }
        if ([] === $dispatch) {
            ExceptionBridge::emitTypeErrorAndAbort($context, $messageForClass('object'));
            $context->builder->positionAtEnd($okBlock);

            return;
        }
        $ids = array_keys($dispatch);
        $lastIdx = \count($ids) - 1;
        foreach ($ids as $idx => $id) {
            $matchBlock = BasicBlockHelper::append($context, 'obj_num_class_match_'.$id);
            $nextBlock = $idx === $lastIdx
                ? $fallbackBlock
                : BasicBlockHelper::append($context, 'obj_num_class_next_'.$id);
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $classId,
                    $i64->constInt($id, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            if ('ok' === $dispatch[$id]) {
                $context->builder->branch($okBlock);
            } else {
                ExceptionBridge::emitTypeErrorAndAbort($context, $messageForClass($dispatch[$id]));
            }
            $context->builder->positionAtEnd($nextBlock);
        }
        $context->builder->positionAtEnd($fallbackBlock);
        ExceptionBridge::emitTypeErrorAndAbort($context, $messageForClass('object'));
        $context->builder->positionAtEnd($okBlock);
    }

    private static function isGuardedArithmeticOp(int $opCode): bool
    {
        return OpCode::TYPE_PLUS === $opCode
            || OpCode::TYPE_MINUS === $opCode
            || OpCode::TYPE_MUL === $opCode
            || OpCode::TYPE_DIV === $opCode
            || OpCode::TYPE_MODULO === $opCode
            || OpCode::TYPE_POW === $opCode
            || OpCode::TYPE_BITWISE_AND === $opCode
            || OpCode::TYPE_BITWISE_OR === $opCode
            || OpCode::TYPE_BITWISE_XOR === $opCode
            || OpCode::TYPE_SHIFT_LEFT === $opCode
            || OpCode::TYPE_SHIFT_RIGHT === $opCode;
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
            OpCode::TYPE_BITWISE_AND => '&',
            OpCode::TYPE_BITWISE_OR => '|',
            OpCode::TYPE_BITWISE_XOR => '^',
            OpCode::TYPE_SHIFT_LEFT => '<<',
            OpCode::TYPE_SHIFT_RIGHT => '>>',
            default => '?',
        };
    }
}
