<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\ext\standard\JitZendScalarCast;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCfg\Func;

/**
 * Scalar return-type enforce/coerce at JIT/AOT return sites (#26427, zend_verify_return_type).
 *
 * Class/enum returns stay in {@see ClassReturnCheck}. Literal native mismatches (e.g.
 * `: string` returning `int` 5) previously emitted raw LLVM of the wrong type and failed
 * module verify — under strict_types raise TypeError; under weak coerce like Zend.
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
        $callableName = self::callableName($block->func);
        $expectedLabel = self::expectedLabel($constraint, $block->returnLiteralBoolType);
        $givenLabel = self::givenLabel($return);
        if ($block->strictTypes) {
            self::raiseReturnTypeError($context, $callableName, $expectedLabel, $givenLabel);

            return false;
        }
        $coerced = self::coerceWeak($context, $return, $expectedJit);
        if (null === $coerced) {
            self::raiseReturnTypeError($context, $callableName, $expectedLabel, $givenLabel);

            return false;
        }
        $return = $coerced;

        return true;
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

    private static function coerceWeak(Context $context, Variable $return, int $expectedJit): ?Variable
    {
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

                return new Variable(
                    $context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    JitZendScalarCast::emitIntCast($context, $return)
                );
            case Variable::TYPE_NATIVE_DOUBLE:
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
        // Same bridge as ClassReturnCheck — catchable when a *local* try is active;
        // otherwise pending + abort (caller try/catch for callee return TypeError is
        // the same gap as class returns today).
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        if (null === $context->builder->getInsertBlock()?->getTerminator()) {
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
    }

    private static function callableName(?Func $func): ?string
    {
        if (null === $func) {
            return null;
        }
        if (null !== $func->class) {
            $className = $func->class->value ?? null;
            if (is_string($className) && '' !== $className) {
                return $className.'::'.$func->name;
            }
        }

        return $func->name;
    }
}
