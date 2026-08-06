<?php
declare(strict_types=1);
namespace PHPCompiler\JIT;
use PHPLLVM\Builder;
use PHPLLVM\Value;
final class JitLongArg {
    public static function lower(Context $context, Variable $arg, string $contextLabel = "argument"): Value {
        if (null !== $arg->compileTimeLong) {
            return $context->constantFromInteger((int) $arg->compileTimeLong);
        }
        if (Variable::TYPE_NATIVE_LONG === $arg->type) return $context->helper->loadValue($arg);
        if (Variable::TYPE_NATIVE_DOUBLE === $arg->type) {
            // zend_dval_to_lval_safe: truncate + E_DEPRECATED on precision loss (#23533).
            return \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning(
                $context,
                $context->helper->loadValue($arg)
            );
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

    /** zend_strtol(..., 0) for file mode numeric strings (#4207). */
    public static function lowerZendLongString(Context $context, Value $strPtr): Value
    {
        return self::lowerStringToLong($context, $strPtr, 0);
    }

    public static function lowerZendLong(Context $context, Variable $arg, string $contextLabel = 'argument'): Value
    {
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning(
                $context,
                $context->helper->loadValue($arg)
            );
        }
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            return $context->builder->zExt($context->helper->loadValue($arg), $context->getTypeFromString('int64'));
        }
        if (JitValueBox::isValueOperand($arg)) {
            return self::lowerValueBoxToLong($context, $arg, 0);
        }
        if (Variable::TYPE_NULL === $arg->type) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $context->builder->ptrToInt($context->helper->loadValue($arg), $context->getTypeFromString('int64'));
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return self::lowerZendLongString($context, $context->helper->loadValue($arg));
        }
        throw new \LogicException("{$contextLabel} must be an integer in this compiler build");
    }

    private static function lowerValueBoxToLong(Context $context, Variable $arg, int $base = 10): Value
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
        $afterString = BasicBlockHelper::append($context, 'jit_long_arg_vbox_after_str');
        $doneBlock = BasicBlockHelper::append($context, 'jit_long_arg_vbox_done');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $stringLong = self::lowerStringToLong($context, $strPtr, $base);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        // Boxed float/double: zend_dval_to_lval + E_DEPRECATED (#23533, #27926).
        // Do not use silent __value__readLong — that skips INF/NAN and fractional warnings.
        $context->builder->positionAtEnd($afterString);
        $doubleBlock = BasicBlockHelper::append($context, 'jit_long_arg_vbox_double');
        $numericBlock = BasicBlockHelper::append($context, 'jit_long_arg_vbox_numeric');
        $isNativeDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        // Value-box writers may tag floats as VM TYPE_FLOAT (2) rather than NATIVE_DOUBLE (3).
        $isVmFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_FLOAT, false)
        );
        $isFloat = $context->builder->or($isNativeDouble, $isVmFloat);
        $context->builder->branchIf($isFloat, $doubleBlock, $numericBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $doubleLong = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning(
            $context,
            $doubleVal
        );
        $doubleEnd = $context->builder->getInsertBlock();
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
        $phi->addIncoming($doubleLong, $doubleEnd);
        $phi->addIncoming($numericLong, $numericEnd);

        return $phi;
    }

    private static function lowerStringToLong(Context $context, Value $strPtr, int $base = 10): Value
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
            $context->getTypeFromString('int32')->constInt($base, false)
        );
        $i64 = $context->getTypeFromString('int64');

        return $parsed->typeOf() === $i64 ? $parsed : $context->builder->zExt($parsed, $i64);
    }
}
