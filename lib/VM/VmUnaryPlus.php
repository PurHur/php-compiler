<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitValueCompare;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SSOT for JIT unary + lowering (#4820, zend_operators.c, #9976).
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitUnaryPlus}
 */
final class VmUnaryPlus
{
    private const WARN_MESSAGE = 'A non-numeric value encountered';

    public static function lower(Context $context, OpCode $opcode, Variable $var): Variable
    {
        if (OpCode::TYPE_UNARY_PLUS !== $opcode->type) {
            throw new \InvalidArgumentException('Expected TYPE_UNARY_PLUS opcode');
        }

        if (null !== $var->objectPropertySlot && null !== $var->objectPropertyType) {
            $jitType = Variable::TYPE_VALUE === $var->objectPropertyType
                ? Variable::TYPE_VALUE
                : $var->objectPropertyType;
        } else {
            $jitType = $var->type;
        }

        switch ($jitType) {
            case Variable::TYPE_NATIVE_LONG:
            case Variable::TYPE_NATIVE_DOUBLE:
                return $var;
            case Variable::TYPE_NATIVE_BOOL:
                $wide = $context->builder->zExt(
                    $context->helper->loadValue($var),
                    $context->getTypeFromString('int64')
                );

                return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $wide);
            case Variable::TYPE_NULL:
                $zero = $context->getTypeFromString('int64')->constInt(0, false);

                return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $zero);
            case Variable::TYPE_STRING:
                return self::lowerStringOperand($context, $var);
            case Variable::TYPE_VALUE:
                if (JitValueBox::isValueOperand($var)) {
                    return self::lowerValueBox($context, $var);
                }

                return self::lowerStringOperand($context, $var);
        }

        throw new \LogicException(
            'Unary + not implemented for JIT operand type '.Variable::getStringType($jitType)
        );
    }

    private static function lowerStringOperand(Context $context, Variable $var): Variable
    {
        $strPtr = Variable::KIND_VALUE === $var->kind
            ? $var->value
            : $context->builder->load($var->value);

        return self::lowerStringPtr($context, $strPtr);
    }

    private static function lowerValueBox(Context $context, Variable $var): Variable
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $stringTy = $i8->constInt(Variable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);
        $stringBlock = BasicBlockHelper::append($context, 'unary_plus_vbox_string');
        $numericBlock = BasicBlockHelper::append($context, 'unary_plus_vbox_numeric');
        $doneBlock = BasicBlockHelper::append($context, 'unary_plus_vbox_done');
        $context->builder->branchIf($isString, $stringBlock, $numericBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $stringLong = $context->helper->loadValue(self::lowerStringPtr($context, $strPtr));
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($numericBlock);
        $numericLong = JitLongArg::lower($context, $var, 'unary plus operand');
        $numericEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $i64 = $context->getTypeFromString('int64');
        $phi = $context->builder->phi($i64, 'unary_plus_vbox_phi');
        $phi->addIncoming($stringLong, $stringEnd);
        $phi->addIncoming($numericLong, $numericEnd);

        return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $phi);
    }

    private static function lowerStringPtr(Context $context, Value $strPtr): Variable
    {
        $isNumeric = JitValueCompare::stringIsNumeric($context, $strPtr);
        $noLeadingPrefix = JitValueCompare::stringHasNoLeadingIntegerPrefix($context, $strPtr);
        $needsTypeError = $context->builder->and($noLeadingPrefix, $context->builder->not($isNumeric));

        $typeErrorBlock = BasicBlockHelper::append($context, 'unary_plus_str_type_error');
        $continueBlock = BasicBlockHelper::append($context, 'unary_plus_str_cont');
        $context->builder->branchIf($needsTypeError, $typeErrorBlock, $continueBlock);

        $context->builder->positionAtEnd($typeErrorBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, 'Unsupported operand types: string * int');
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($continueBlock);
        $warnBlock = BasicBlockHelper::append($context, 'unary_plus_str_warn');
        $afterWarnBlock = BasicBlockHelper::append($context, 'unary_plus_str_after_warn');
        $context->builder->branchIf($isNumeric, $afterWarnBlock, $warnBlock);
        $context->builder->positionAtEnd($warnBlock);
        self::emitNonNumericWarning($context);
        $context->builder->branch($afterWarnBlock);
        $context->builder->positionAtEnd($afterWarnBlock);

        $numericLong = self::numericStringToLong($context, $strPtr);

        return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $numericLong);
    }

    private static function numericStringToLong(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'unary_plus_strtol_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $parsed = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $i64 = $context->getTypeFromString('int64');

        return $parsed->typeOf() === $i64 ? $parsed : $context->builder->zExt($parsed, $i64);
    }

    private static function emitNonNumericWarning(Context $context): void
    {
        $message = self::WARN_MESSAGE;
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }
}
