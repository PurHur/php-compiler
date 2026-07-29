<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Bcmath;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitValueNumeric;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT BcMath\Number do_operation (#24683).
 *
 * php-src: ext/bcmath/bcmath.c — bcmath_number_do_operation
 * VM SSOT: {@see VmBcMathNumber::tryDoOperation}
 *
 * Uses existing {@see __compiler_bcadd} etc. (no NestedJIT Number helpers).
 */
final class JitBcMathNumberOperators
{
    public static function binaryValueValue(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): Variable {
        if (!\PHPCompiler\CompilerVersion::supportsBcmath()) {
            return JitValueNumeric::emitBoxedNumericResultPublic($context, $opType, $left, $right);
        }

        self::ensureLinked($context);

        $numberId = $context->type->object->lookup(JitBcMathNumberInit::classDisplayName());
        $leftPtr = JitValueBox::valuePtrFromVariable($context, $left);
        $rightPtr = JitValueBox::valuePtrFromVariable($context, $right);
        $map = $context->structFieldMap['__value__'];
        $objMap = $context->structFieldMap['__object__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $objTag = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $wantId = $i64->constInt($numberId, false);

        $leftKindRaw = $context->builder->load($context->builder->structGep($leftPtr, $map['type']));
        $rightKindRaw = $context->builder->load($context->builder->structGep($rightPtr, $map['type']));
        $mask = $i8->constInt(0x7f, false);
        $leftKind = $context->builder->and($leftKindRaw, $mask);
        $rightKind = $context->builder->and($rightKindRaw, $mask);
        $leftObj = $context->builder->call($context->lookupFunction('__value__readObject'), $leftPtr);
        $rightObj = $context->builder->call($context->lookupFunction('__value__readObject'), $rightPtr);
        $leftIsNumber = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftKind, $objTag),
            $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($context->builder->structGep($leftObj, $objMap['class_id'])),
                $wantId
            )
        );
        $rightIsNumber = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $rightKind, $objTag),
            $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($context->builder->structGep($rightObj, $objMap['class_id'])),
                $wantId
            )
        );
        $eitherNumber = $context->builder->or($leftIsNumber, $rightIsNumber);

        $numberBlock = BasicBlockHelper::append($context, 'bcmath_vv_number');
        $scalarBlock = BasicBlockHelper::append($context, 'bcmath_vv_scalar');
        $doneBlock = BasicBlockHelper::append($context, 'bcmath_vv_done');
        $resultSlot = JitValueBox::alloc($context);
        $context->builder->branchIf($eitherNumber, $numberBlock, $scalarBlock);

        $context->builder->positionAtEnd($numberBlock);
        $bothNumber = $context->builder->and($leftIsNumber, $rightIsNumber);
        $ok = BasicBlockHelper::append($context, 'bcmath_vv_both');
        $bad = BasicBlockHelper::append($context, 'bcmath_vv_bad');
        $context->builder->branchIf($bothNumber, $ok, $bad);
        $context->builder->positionAtEnd($bad);
        \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitRaise(
            $context,
            'Unsupported operand types for BcMath\\Number operator'
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($ok);
        self::emitNumberBinaryIntoSlot(
            $context,
            $opType,
            $leftObj,
            $rightObj,
            $resultSlot
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($scalarBlock);
        $scalar = JitValueNumeric::emitBoxedNumericResultPublic($context, $opType, $left, $right);
        JitValueBox::copyFromPointer(
            $context,
            $resultSlot,
            JitValueBox::valuePtrFromVariable($context, $scalar)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $resultSlot);
    }

    /**
     * OBJECT ⊙ OBJECT — main-script temps stay typed as objects after `new` (#24683).
     */
    public static function binaryObjectObject(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): Variable {
        self::ensureLinked($context);

        $numberId = $context->type->object->lookup(JitBcMathNumberInit::classDisplayName());
        $objMap = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');
        $wantId = $i64->constInt($numberId, false);

        $leftObj = JitBcMathNumberInit::loadObjectFromArg($context, $left);
        $rightObj = JitBcMathNumberInit::loadObjectFromArg($context, $right);
        $leftIsNumber = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->structGep($leftObj, $objMap['class_id'])),
            $wantId
        );
        $rightIsNumber = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->structGep($rightObj, $objMap['class_id'])),
            $wantId
        );
        $bothNumber = $context->builder->and($leftIsNumber, $rightIsNumber);

        $ok = BasicBlockHelper::append($context, 'bcmath_oo_both');
        $bad = BasicBlockHelper::append($context, 'bcmath_oo_bad');
        $done = BasicBlockHelper::append($context, 'bcmath_oo_done');
        $resultSlot = JitValueBox::alloc($context);
        $context->builder->branchIf($bothNumber, $ok, $bad);

        $context->builder->positionAtEnd($bad);
        \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitRaise(
            $context,
            'Unsupported operand types for BcMath\\Number operator'
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($ok);
        self::emitNumberBinaryIntoSlot($context, $opType, $leftObj, $rightObj, $resultSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $resultSlot);
    }

    private static function ensureLinked(Context $context): void
    {
        $resume = BasicBlockHelper::tryGetInsertBlock($context);
        Bcmath::ensureLinked($context);
        \PHPCompiler\JIT\Builtin\TypeErrorRaise::ensureLinked($context);
        if (null !== $resume) {
            BasicBlockHelper::restoreInsertBlock($context, $resume);
        }
    }

    private static function emitNumberBinaryIntoSlot(
        Context $context,
        int $opType,
        Value $leftObj,
        Value $rightObj,
        Value $resultSlot
    ): void {
        $leftRecv = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $leftObj);
        $rightRecv = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $rightObj);
        $leftVal = JitBcMathNumberInit::loadValueString($context, $leftRecv);
        $rightVal = JitBcMathNumberInit::loadValueString($context, $rightRecv);
        $leftScale = JitBcMathNumberInit::loadScaleLong($context, $leftRecv);
        $rightScale = JitBcMathNumberInit::loadScaleLong($context, $rightRecv);
        [$outVal, $outScale] = self::emitBinaryBc(
            $context,
            $opType,
            $leftVal,
            $leftScale,
            $rightVal,
            $rightScale
        );
        $boxed = JitBcMathNumberInit::boxNewNumber($context, $outVal, $outScale);
        JitValueBox::copyFromPointer(
            $context,
            $resultSlot,
            JitValueBox::valuePtrFromVariable($context, $boxed)
        );
    }

    /**
     * @return array{0: Value, 1: Value}
     */
    private static function emitBinaryBc(
        Context $context,
        int $opType,
        Value $leftVal,
        Value $leftScale,
        Value $rightVal,
        Value $rightScale
    ): array {
        $i64 = $context->getTypeFromString('int64');
        $hasScale = $i64->constInt(1, true);
        $round = $i64->constInt(0, true);
        $hasRound = $i64->constInt(-1, true);
        switch ($opType) {
            case OpCode::TYPE_PLUS:
            case OpCode::TYPE_MINUS:
                $scale = $context->builder->select(
                    $context->builder->icmp(Builder::INT_SGT, $leftScale, $rightScale),
                    $leftScale,
                    $rightScale
                );
                $fn = OpCode::TYPE_PLUS === $opType ? '__compiler_bcadd' : '__compiler_bcsub';
                $out = $context->builder->call(
                    $context->lookupFunction($fn),
                    $leftVal,
                    $rightVal,
                    $scale,
                    $hasScale,
                    $round,
                    $hasRound
                );

                return [$out, $scale];
            case OpCode::TYPE_MUL:
                $scale = $context->builder->add($leftScale, $rightScale);
                $out = $context->builder->call(
                    $context->lookupFunction('__compiler_bcmul'),
                    $leftVal,
                    $rightVal,
                    $scale,
                    $hasScale,
                    $round,
                    $hasRound
                );

                return [$out, $scale];
            case OpCode::TYPE_DIV:
                $scale = $context->builder->add(
                    $leftScale,
                    $i64->constInt(VmBcMathNumber::EXPAND_SCALE, true)
                );
                $out = $context->builder->call(
                    $context->lookupFunction('__compiler_bcdiv'),
                    $leftVal,
                    $rightVal,
                    $scale,
                    $hasScale,
                    $round,
                    $hasRound
                );

                return [$out, $scale];
            default:
                throw new \LogicException('BcMath\\Number JIT op not supported: '.$opType);
        }
    }
}
