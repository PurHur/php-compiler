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
 * Compile-time Number operands fold via {@see VmBcMathNumber::computeBinary} so AOT
 * does not store NestedJIT __string__* results into Number::$value (#24137).
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

        $folded = self::tryFoldCompileTime($context, $opType, $left, $right);
        if (null !== $folded) {
            return $folded;
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
        self::emitNumberBinaryIntoSlot($context, $opType, $leftObj, $rightObj, $resultSlot);
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

    public static function binaryObjectObject(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): Variable {
        $folded = self::tryFoldCompileTime($context, $opType, $left, $right);
        if (null !== $folded) {
            return $folded;
        }

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


    /**
     * Fold Number⊙Number when both sides carry construct/fold metadata (#24683).
     *
     * Avoids NestedJIT __compiler_bc* string results, which cannot be stored into
     * Number::$value and echoed under AOT (length-ok / content UAF, #24137).
     */
    private static function tryFoldCompileTime(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): ?Variable {
        $leftCt = $left->compileTimeBcmathNumber ?? null;
        $rightCt = $right->compileTimeBcmathNumber ?? null;
        if (null === $leftCt || null === $rightCt) {
            return null;
        }
        if (
            OpCode::TYPE_PLUS !== $opType
            && OpCode::TYPE_MINUS !== $opType
            && OpCode::TYPE_MUL !== $opType
            && OpCode::TYPE_DIV !== $opType
        ) {
            return null;
        }

        [$outValue, $outScale] = VmBcMathNumber::computeBinary(
            $opType,
            $leftCt['value'],
            $leftCt['scale'],
            $rightCt['value'],
            $rightCt['scale'],
            true
        );
        $valueStr = $context->builder->load($context->constantStringFromString($outValue));
        $scaleLong = $context->getTypeFromString('int64')->constInt($outScale, true);

        return JitBcMathNumberInit::boxNewNumber(
            $context,
            $valueStr,
            $scaleLong,
            ['value' => $outValue, 'scale' => $outScale]
        );
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
        $leftStrVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            JitBcMathNumberInit::loadValueString($context, $leftRecv)
        );
        $rightStrVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            JitBcMathNumberInit::loadValueString($context, $rightRecv)
        );
        $leftScale = JitBcMathNumberInit::loadScaleLong($context, $leftRecv);
        $rightScale = JitBcMathNumberInit::loadScaleLong($context, $rightRecv);
        $i64 = $context->getTypeFromString('int64');
        $left = \PHPCompiler\JIT\JitStringBuiltinArg::lower($context, $leftStrVar, 'bcmath_number', 0, 'num1');
        $right = \PHPCompiler\JIT\JitStringBuiltinArg::lower($context, $rightStrVar, 'bcmath_number', 1, 'num2');
        switch ($opType) {
            case OpCode::TYPE_PLUS:
            case OpCode::TYPE_MINUS:
                $scale = $context->builder->select(
                    $context->builder->icmp(Builder::INT_SGT, $leftScale, $rightScale),
                    $leftScale,
                    $rightScale
                );
                $fn = OpCode::TYPE_PLUS === $opType ? '__compiler_bcadd' : '__compiler_bcsub';
                break;
            case OpCode::TYPE_MUL:
                $scale = $context->builder->add($leftScale, $rightScale);
                $fn = '__compiler_bcmul';
                break;
            case OpCode::TYPE_DIV:
                $scale = $context->builder->add(
                    $leftScale,
                    $i64->constInt(VmBcMathNumber::EXPAND_SCALE, true)
                );
                $fn = '__compiler_bcdiv';
                break;
            default:
                throw new \LogicException('BcMath\\Number JIT op not supported: '.$opType);
        }
        $outVal = $context->builder->call(
            $context->lookupFunction($fn),
            $left,
            $right,
            $scale,
            $i64->constInt(1, true),
            $i64->constInt(0, true),
            $i64->constInt(-1, true)
        );
        $outVal = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $outVal
        );
        $boxed = JitBcMathNumberInit::boxNewNumber($context, $outVal, $scale);
        JitValueBox::copyFromPointer(
            $context,
            $resultSlot,
            JitValueBox::valuePtrFromVariable($context, $boxed)
        );
    }
}
