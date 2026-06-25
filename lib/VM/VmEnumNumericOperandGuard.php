<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as JitObjectType;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * SSOT for JIT enum-case arithmetic operand guards (#5790, #5794, zend_operators.c, #9976).
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitEnumNumericOperandGuard}
 */
final class VmEnumNumericOperandGuard
{
    public static function guardArithmetic(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): void {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        if (!self::isGuardedArithmeticOp($opCode)) {
            return;
        }
        self::guardOperands($context, $opCode, $left, $right);
    }

    public static function guardPow(Context $context, Variable $base, Variable $exp): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        self::guardOperands($context, OpCode::TYPE_POW, $base, $exp);
    }

    public static function guardModulo(Context $context, Variable $left, Variable $right): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        self::guardOperands($context, OpCode::TYPE_MODULO, $left, $right);
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

    private static function guardOperands(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): void {
        $message = self::compileTimeMessage($context, $opCode, $left, $right);
        if (null !== $message) {
            self::emitTypeErrorAndAbort($context, $message);

            return;
        }
        self::emitRuntimeEnumOperandGuard($context, $opCode, $left);
        self::emitRuntimeEnumOperandGuard($context, $opCode, $right);
    }

    private static function compileTimeMessage(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): ?string {
        $leftLabel = self::compileTimeEnumLabel($context, $left);
        $rightLabel = self::compileTimeEnumLabel($context, $right);
        if (null === $leftLabel || null === $rightLabel) {
            return null;
        }

        return sprintf(
            'Unsupported operand types: %s %s %s',
            $leftLabel,
            self::operatorSymbol($opCode),
            $rightLabel
        );
    }

    private static function compileTimeEnumLabel(Context $context, Variable $var): ?string
    {
        if (Variable::TYPE_OBJECT !== $var->type) {
            return null;
        }
        $classId = self::constantObjectClassId($context, $var);
        if (null === $classId) {
            return null;
        }
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            return null;
        }
        $lc = strtolower(ltrim($jitObject->classNameForId($classId), '\\'));
        if (!isset($jitObject->enums[$lc])) {
            return null;
        }

        return $jitObject->classNameForId($classId);
    }

    private static function constantObjectClassId(Context $context, Variable $var): ?int
    {
        if (Variable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            return null;
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($var->value, $objMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return null;
        }

        return (int) $classIdVal->getConstantValue();
    }

    private static function emitRuntimeEnumOperandGuard(
        Context $context,
        int $opCode,
        Variable $var
    ): void {
        if (Variable::TYPE_VALUE === $var->type && JitValueBox::isValueOperand($var)) {
            self::emitValueBoxEnumReject($context, $opCode, $var);

            return;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            self::emitObjectEnumReject($context, $opCode, $var);
        }
    }

    private static function emitValueBoxEnumReject(Context $context, int $opCode, Variable $var): void
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $okBlock = BasicBlockHelper::append($context, 'enum_pow_mod_vbox_ok');
        $rejectBlock = BasicBlockHelper::append($context, 'enum_pow_mod_vbox_reject');
        $context->builder->branchIf($isEnumCase, $rejectBlock, $okBlock);
        $context->builder->positionAtEnd($rejectBlock);
        self::emitTypeErrorAndAbort(
            $context,
            sprintf(
                'Unsupported operand types: mixed %s mixed',
                self::operatorSymbol($opCode)
            )
        );
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitObjectEnumReject(Context $context, int $opCode, Variable $var): void
    {
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            return;
        }
        $enumNames = $jitObject->allDeclaredEnumLowerNames();
        if ([] === $enumNames) {
            return;
        }
        $objPtr = Variable::KIND_VALUE === $var->kind
            ? $var->value
            : $context->builder->load($var->value);
        $map = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $fn = BasicBlockHelper::parentFunction($context);
        $checkBlock = $context->builder->getInsertBlock();
        $okBlock = BasicBlockHelper::append($context, 'enum_pow_mod_obj_ok');
        $ids = [];
        foreach ($enumNames as $lc) {
            $ids[] = [$jitObject->lookup($lc), $jitObject->classNameForId($jitObject->lookup($lc))];
        }
        $lastIdx = \count($ids) - 1;
        foreach ($ids as $idx => [$enumId, $enumName]) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $runtimeClassId,
                $i64->constInt($enumId, false)
            );
            $rejectBlock = $fn->appendBasicBlock('enum_pow_mod_obj_reject_'.$enumId);
            $nextBlock = $idx === $lastIdx
                ? $okBlock
                : $fn->appendBasicBlock('enum_pow_mod_obj_try_'.($idx + 1));
            $context->builder->branchIf($match, $rejectBlock, $nextBlock);
            $context->builder->positionAtEnd($rejectBlock);
            self::emitTypeErrorAndAbort(
                $context,
                sprintf(
                    'Unsupported operand types: %s %s mixed',
                    $enumName,
                    self::operatorSymbol($opCode)
                )
            );
            $checkBlock = $nextBlock;
        }
        if ($checkBlock !== $okBlock) {
            $context->builder->positionAtEnd($checkBlock);
            $context->builder->branch($okBlock);
        }
        $context->builder->positionAtEnd($okBlock);
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

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }
}
