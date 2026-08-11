<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\ext\standard\JitZendScalarCast;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCfg\Func;
use PHPLLVM\Builder;

/**
 * Scalar return-type enforce/coerce at JIT/AOT return sites (#26427, zend_verify_return_type).
 *
 * Class/enum returns stay in {@see ClassReturnCheck}. Literal native mismatches (e.g.
 * `: string` returning `int` 5) previously emitted raw LLVM of the wrong type and failed
 * module verify — under strict_types raise TypeError; under weak coerce like Zend.
 *
 * Value boxes (array dim / property reads) must be runtime-verified, not rejected as
 * static "mixed" — Zend accepts `return $cfg['app_name']` when the cell is a string (#29001).
 */
final class ScalarReturnCheck
{
    /**
     * @param-out Variable $return
     *
     * @return bool false when a TypeError path was emitted (caller must not emit ret)
     */
    public static function enforce(Context $context, Block $block, Variable &$return): bool
    {
        // NestedJIT helpers use a different ABI; do not inject user-script return checks (#26427).
        if (NestedJitCompileScope::isActive()) {
            return true;
        }
        $constraint = $block->returnTypeConstraint;
        if (null === $constraint || VMVariable::TYPE_OBJECT === $constraint) {
            return true;
        }
        $expectedJit = self::jitTypeFromVmConstraint($constraint);
        if (null === $expectedJit) {
            return true;
        }
        if ($return->type === $expectedJit) {
            return true;
        }
        $callableName = self::callableName($block);
        $expectedLabel = self::expectedLabel($constraint, $block->returnLiteralBoolType);
        $givenLabel = self::givenLabel($return);
        // Boxed cells: zend_verify_return_type inspects the runtime tag (#29001 MiniWebApp).
        if (Variable::TYPE_VALUE === $return->type) {
            if ($block->strictTypes) {
                return self::enforceValueBoxStrict(
                    $context,
                    $return,
                    $expectedJit,
                    $constraint,
                    $callableName,
                    $expectedLabel
                );
            }
            $coerced = self::coerceWeak(
                $context,
                $return,
                $expectedJit,
                $callableName,
                $expectedLabel
            );
            if (null === $coerced) {
                self::raiseReturnTypeError($context, $callableName, $expectedLabel, $givenLabel);

                return false;
            }
            $return = $coerced;

            return true;
        }
        if ($block->strictTypes) {
            // Zend zend_verify_return_type: int→float widening under strict_types (#28615).
            if (
                Variable::TYPE_NATIVE_DOUBLE === $expectedJit
                && Variable::TYPE_NATIVE_LONG === $return->type
            ) {
                $return = new Variable(
                    $context,
                    Variable::TYPE_NATIVE_DOUBLE,
                    Variable::KIND_VALUE,
                    $context->builder->siToFp(
                        $context->helper->loadValue($return),
                        $context->getTypeFromString('double')
                    )
                );

                return true;
            }
            self::raiseReturnTypeError($context, $callableName, $expectedLabel, $givenLabel);

            return false;
        }
        $coerced = self::coerceWeak(
            $context,
            $return,
            $expectedJit,
            $callableName,
            $expectedLabel
        );
        if (null === $coerced) {
            self::raiseReturnTypeError($context, $callableName, $expectedLabel, $givenLabel);

            return false;
        }
        $return = $coerced;

        return true;
    }

    /**
     * Runtime-verify a `__value__` box against a scalar return type (strict_types).
     *
     * @param-out Variable $return
     *
     * @return bool false when a TypeError path was emitted
     */
    private static function enforceValueBoxStrict(
        Context $context,
        Variable &$return,
        int $expectedJit,
        int $constraint,
        ?string $callableName,
        string $expectedLabel
    ): bool {
        $expectedVm = self::vmTypeFromVmConstraint($constraint);
        if (null === $expectedVm) {
            self::raiseReturnTypeError($context, $callableName, $expectedLabel, 'mixed');

            return false;
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $return);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $okBb = $fn->appendBasicBlock('scalar_return_value_ok');
        $failBb = $fn->appendBasicBlock('scalar_return_value_fail');
        $resumeBb = $fn->appendBasicBlock('scalar_return_value_resume');

        $isMatch = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt($expectedVm, false)
        );
        // int→float widening under strict_types (#28615) for boxed integers.
        if (Variable::TYPE_NATIVE_DOUBLE === $expectedJit) {
            $isInt = $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i8->constInt(VMVariable::TYPE_INTEGER, false)
            );
            $isMatch = $context->builder->or($isMatch, $isInt);
        }
        $context->builder->branchIf($isMatch, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        self::raiseReturnTypeError($context, $callableName, $expectedLabel, 'mixed');
        if (null === $context->builder->getInsertBlock()?->getTerminator()) {
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }

        $context->builder->positionAtEnd($okBb);
        $unwrapped = self::unwrapValueBox($context, $valuePtr, $expectedJit, $kind);
        $context->builder->branch($resumeBb);
        $context->builder->positionAtEnd($resumeBb);
        $return = $unwrapped;

        return true;
    }

    private static function vmTypeFromVmConstraint(int $constraint): ?int
    {
        return match ($constraint) {
            VMVariable::TYPE_INTEGER => VMVariable::TYPE_INTEGER,
            VMVariable::TYPE_FLOAT => VMVariable::TYPE_FLOAT,
            VMVariable::TYPE_BOOLEAN => VMVariable::TYPE_BOOLEAN,
            VMVariable::TYPE_STRING => VMVariable::TYPE_STRING,
            VMVariable::TYPE_ARRAY => VMVariable::TYPE_ARRAY,
            default => null,
        };
    }

    private static function unwrapValueBox(
        Context $context,
        \PHPLLVM\Value $valuePtr,
        int $expectedJit,
        \PHPLLVM\Value $kind
    ): Variable {
        $i8 = $context->getTypeFromString('int8');
        switch ($expectedJit) {
            case Variable::TYPE_STRING:
                return new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    $context->builder->call(
                        $context->lookupFunction('__value__readString'),
                        $valuePtr
                    )
                );
            case Variable::TYPE_NATIVE_LONG:
                return new Variable(
                    $context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $context->builder->call(
                        $context->lookupFunction('__value__readLong'),
                        $valuePtr
                    )
                );
            case Variable::TYPE_NATIVE_DOUBLE:
                // Widen boxed int → float when the tag was integer.
                $fn = $context->builder->getInsertBlock()->getParent();
                assert($fn instanceof \PHPLLVM\Value\Function_);
                $fromIntBb = $fn->appendBasicBlock('scalar_return_box_int_to_float');
                $fromFloatBb = $fn->appendBasicBlock('scalar_return_box_float');
                $joinBb = $fn->appendBasicBlock('scalar_return_box_float_join');
                $isInt = $context->builder->icmp(
                    Builder::INT_EQ,
                    $kind,
                    $i8->constInt(VMVariable::TYPE_INTEGER, false)
                );
                $context->builder->branchIf($isInt, $fromIntBb, $fromFloatBb);

                $context->builder->positionAtEnd($fromIntBb);
                $asLong = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $widened = $context->builder->siToFp($asLong, $context->getTypeFromString('double'));
                $intEnd = $context->builder->getInsertBlock();
                $context->builder->branch($joinBb);

                $context->builder->positionAtEnd($fromFloatBb);
                $asDouble = $context->builder->call(
                    $context->lookupFunction('__value__readDouble'),
                    $valuePtr
                );
                $floatEnd = $context->builder->getInsertBlock();
                $context->builder->branch($joinBb);

                $context->builder->positionAtEnd($joinBb);
                $phi = $context->builder->phi($context->getTypeFromString('double'));
                $phi->addIncoming($widened, $intEnd);
                $phi->addIncoming($asDouble, $floatEnd);

                return new Variable(
                    $context,
                    Variable::TYPE_NATIVE_DOUBLE,
                    Variable::KIND_VALUE,
                    $phi
                );
            case Variable::TYPE_NATIVE_BOOL:
                // Bool lives in the value-union first byte (same as JitBoolArg).
                $map = $context->structFieldMap['__value__'];
                $valueField = $context->builder->structGep($valuePtr, $map['value']);
                $firstByte = $context->builder->inBoundsGEP(
                    $valueField,
                    $context->getTypeFromString('int32')->constInt(0, false),
                    $context->getTypeFromString('int64')->constInt(0, false)
                );

                return new Variable(
                    $context,
                    Variable::TYPE_NATIVE_BOOL,
                    Variable::KIND_VALUE,
                    $context->castToBool($context->builder->load($firstByte))
                );
            case Variable::TYPE_HASHTABLE:
                return new Variable(
                    $context,
                    Variable::TYPE_HASHTABLE,
                    Variable::KIND_VALUE,
                    $context->builder->call(
                        $context->lookupFunction('__value__readHashtable'),
                        $valuePtr
                    )
                );
            default:
                throw new \LogicException('ScalarReturnCheck: unsupported value-box unwrap');
        }
    }

    private static function jitTypeFromVmConstraint(int $constraint): ?int
    {
        return match ($constraint) {
            VMVariable::TYPE_INTEGER => Variable::TYPE_NATIVE_LONG,
            VMVariable::TYPE_FLOAT => Variable::TYPE_NATIVE_DOUBLE,
            VMVariable::TYPE_BOOLEAN => Variable::TYPE_NATIVE_BOOL,
            VMVariable::TYPE_STRING => Variable::TYPE_STRING,
            VMVariable::TYPE_ARRAY => Variable::TYPE_HASHTABLE,
            default => null,
        };
    }

    private static function expectedLabel(int $constraint, ?string $literalBoolType): string
    {
        if (null !== $literalBoolType && '' !== $literalBoolType) {
            return $literalBoolType;
        }

        return match ($constraint) {
            VMVariable::TYPE_INTEGER => 'int',
            VMVariable::TYPE_FLOAT => 'float',
            VMVariable::TYPE_BOOLEAN => 'bool',
            VMVariable::TYPE_STRING => 'string',
            VMVariable::TYPE_ARRAY => 'array',
            VMVariable::TYPE_NULL => 'null',
            default => 'mixed',
        };
    }

    private static function givenLabel(Variable $return): string
    {
        if (Variable::TYPE_NATIVE_BOOL === $return->type) {
            $value = $return->value;
            if (method_exists($value, 'isConstant') && $value->isConstant()
                && method_exists($value, 'getConstantValue')
            ) {
                // zend_execute.c — true/false returned (#29097).
                return 0 !== (int) $value->getConstantValue() ? 'true' : 'false';
            }
        }

        return match ($return->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_HASHTABLE => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }

    private static function coerceWeak(
        Context $context,
        Variable $return,
        int $expectedJit,
        ?string $callableName,
        string $expectedLabel
    ): ?Variable {
        switch ($expectedJit) {
            case Variable::TYPE_STRING:
                try {
                    return JitNativeString::coerce($context, $return);
                } catch (\LogicException $e) {
                    return null;
                }
            case Variable::TYPE_NATIVE_LONG:
                if (Variable::TYPE_NATIVE_DOUBLE === $return->type) {
                    return new Variable(
                        $context,
                        Variable::TYPE_NATIVE_LONG,
                        Variable::KIND_VALUE,
                        \PHPCompiler\ext\standard\JitIntdiv::floatToLongTypedSafe(
                            $context,
                            $context->helper->loadValue($return),
                            'Return value must be of type int, float returned'
                        )
                    );
                }
                // zend_verify_return_type: non-numeric strings TypeError (not intval→0) (#29858).
                if (Variable::TYPE_STRING === $return->type) {
                    return self::coerceWeakIntFromString(
                        $context,
                        $return,
                        $callableName,
                        $expectedLabel
                    );
                }

                return new Variable(
                    $context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    JitZendScalarCast::emitIntCast($context, $return)
                );
            case Variable::TYPE_NATIVE_DOUBLE:
                // Keep emitFloatCast for non-string; string uses is_numeric like VM (#29858 sibling).
                if (Variable::TYPE_STRING === $return->type) {
                    $literal = JitStringArg::compileTimeLiteral($return);
                    if (null !== $literal) {
                        if ('' === $literal || !is_numeric($literal)) {
                            return null;
                        }

                        return new Variable(
                            $context,
                            Variable::TYPE_NATIVE_DOUBLE,
                            Variable::KIND_VALUE,
                            $context->getTypeFromString('double')->constReal((float) $literal)
                        );
                    }
                    $strPtr = JitStringArg::lower($context, $return, 'Return value');
                    $isNumeric = TypedParamCoerce::stringIsNumeric($context, $strPtr);
                    $okBlock = BasicBlockHelper::append($context, 'scalar_return_float_str_ok');
                    $failBlock = BasicBlockHelper::append($context, 'scalar_return_float_str_fail');
                    $context->builder->branchIf($isNumeric, $okBlock, $failBlock);

                    $context->builder->positionAtEnd($failBlock);
                    self::raiseReturnTypeError($context, $callableName, $expectedLabel, 'string');

                    $context->builder->positionAtEnd($okBlock);
                    $doubleVal = JitZendScalarCast::emitFloatCast($context, $return);

                    return new Variable(
                        $context,
                        Variable::TYPE_NATIVE_DOUBLE,
                        Variable::KIND_VALUE,
                        $doubleVal
                    );
                }

                return new Variable(
                    $context,
                    Variable::TYPE_NATIVE_DOUBLE,
                    Variable::KIND_VALUE,
                    JitZendScalarCast::emitFloatCast($context, $return)
                );
            case Variable::TYPE_NATIVE_BOOL:
                return new Variable(
                    $context,
                    Variable::TYPE_NATIVE_BOOL,
                    Variable::KIND_VALUE,
                    JitBoolArg::lowerCoerce($context, $return, 'Return value')
                );
            default:
                return null;
        }
    }

    /**
     * Weak `: int` from string — reject non-numeric like VM TypeCheck::coerceToInt (#29858).
     *
     * @return Variable|null null when a compile-time literal is non-numeric (caller raises)
     */
    private static function coerceWeakIntFromString(
        Context $context,
        Variable $return,
        ?string $callableName,
        string $expectedLabel
    ): ?Variable {
        $literal = JitStringArg::compileTimeLiteral($return);
        if (null !== $literal) {
            if ('' === $literal || !is_numeric($literal)) {
                return null;
            }

            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger((int) (float) $literal)
            );
        }

        $strPtr = JitStringArg::lower($context, $return, 'Return value');
        $isNumeric = TypedParamCoerce::stringIsNumeric($context, $strPtr);
        $okBlock = BasicBlockHelper::append($context, 'scalar_return_int_str_ok');
        $failBlock = BasicBlockHelper::append($context, 'scalar_return_int_str_fail');
        $context->builder->branchIf($isNumeric, $okBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        self::raiseReturnTypeError($context, $callableName, $expectedLabel, 'string');

        $context->builder->positionAtEnd($okBlock);
        $longVal = JitLongArg::lowerStringValue($context, $strPtr);

        return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $longVal);
    }

    private static function raiseReturnTypeError(
        Context $context,
        ?string $callableName,
        string $expected,
        string $given
    ): void {
        $message = "Return value must be of type {$expected}, {$given} returned";
        if (null !== $callableName && '' !== $callableName) {
            $message = "{$callableName}(): {$message}";
        }
        // Local try: catchable immediately. Cross-function (caller try): pend + return so
        // invokeJitCall's emitCheckPendingThrowAfterCall can catch (#26486 / #29858).
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            ExceptionBridge::emitTypeErrorAndAbort($context, $message);
            if (null === $context->builder->getInsertBlock()?->getTerminator()) {
                $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            }

            return;
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            Builtin\TypeErrorRaise::registerDeclarations($context);
            Builtin\TypeErrorRaise::ensureLinked($context);
            Builtin\TypeErrorRaise::ensureStandaloneBodies($context);
            TryCatchHelper::emitPendTypeErrorForCaller($context, $message);
            Builtin\TypeErrorRaise::emitRaise($context, $message);
            $fn = $context->builder->getInsertBlock()?->getParent();
            if ($fn instanceof \PHPLLVM\Value\Function_) {
                TryCatchHelper::emitPropagateReturnAfterPendingThrow($context, $fn);
            }

            return;
        }
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        if (null === $context->builder->getInsertBlock()?->getTerminator()) {
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
    }

    private static function callableName(Block $block): ?string
    {
        $func = $block->func;
        if (null === $func && (null === $block->closureRichDisplayName || '' === $block->closureRichDisplayName)) {
            return null;
        }

        return \PHPCompiler\VM\ParamArgumentCountError::typeErrorDisplayNameForCfgFunc($func, null, $block);
    }
}
