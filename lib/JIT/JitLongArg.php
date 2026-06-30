<?php
declare(strict_types=1);
namespace PHPCompiler\JIT;
use PHPLLVM\Builder;
use PHPLLVM\Value;
final class JitLongArg {
    public static function lower(Context $context, Variable $arg, string $contextLabel = "argument"): Value {
        if (Variable::TYPE_NATIVE_LONG === $arg->type) return $context->helper->loadValue($arg);
        if (Variable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->builder->fpToSi($context->helper->loadValue($arg), $context->getTypeFromString('int64'));
        }
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) return $context->builder->zExt($context->helper->loadValue($arg), $context->getTypeFromString("int64"));
        if (JitValueBox::isValueOperand($arg)) {
            return self::lowerValueBoxToLong($context, $arg);
        }
        if (Variable::TYPE_NULL === $arg->type) return $context->getTypeFromString("int64")->constInt(0, false);
        if (Variable::TYPE_OBJECT === $arg->type) return $context->builder->ptrToInt($context->helper->loadValue($arg), $context->getTypeFromString("int64"));
        if (Variable::TYPE_STRING === $arg->type) {
            return self::lowerStringValue($context, $context->helper->loadValue($arg));
        }
        throw new \LogicException("{$contextLabel} must be an integer in this compiler build");
    }

    public static function lowerStringValue(Context $context, Value $strPtr): Value
    {
        return self::lowerStringToLong($context, $strPtr);
    }

    private static function lowerValueBoxToLong(Context $context, Variable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $stringTy = $i8->constInt(Variable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);
        $stringBlock = BasicBlockHelper::append($context, 'jit_long_arg_vbox_string');
        $numericBlock = BasicBlockHelper::append($context, 'jit_long_arg_vbox_numeric');
        $doneBlock = BasicBlockHelper::append($context, 'jit_long_arg_vbox_done');
        $context->builder->branchIf($isString, $stringBlock, $numericBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $stringLong = self::lowerStringToLong($context, $strPtr);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($numericBlock);
        $numericLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $numericEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $i64 = $context->getTypeFromString('int64');
        $phi = $context->builder->phi($i64, 'jit_long_arg_vbox_phi');
        $phi->addIncoming($stringLong, $stringEnd);
        $phi->addIncoming($numericLong, $numericEnd);

        return $phi;
    }

    private static function lowerStringToLong(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'jit_long_arg_strtol_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $parsed = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $i64 = $context->getTypeFromString('int64');

        return $parsed->typeOf() === $i64 ? $parsed : $context->builder->zExt($parsed, $i64);
    }
}
