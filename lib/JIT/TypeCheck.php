<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitZendScalarCast;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Builder;

/**
 * Strict scalar type checks for typed JIT call arguments (issues #156, #1229).
 *
 * Fixed-arity weak coercion is handled in {@see Call\Native::compileArg} when lowering to
 * native LLVM types. Typed variadic elements are packed as a hashtable of values, so weak
 * coercion must happen before pack (#26587).
 */
final class TypeCheck
{
    public static function enforceParameter(
        Context $context,
        Variable $var,
        int $vmConstraint,
        bool $strict
    ): void {
        if (!$strict) {
            return;
        }
        $expected = Variable::fromVMVariable($vmConstraint);
        if ($var->type === $expected) {
            return;
        }
        if (
            Variable::TYPE_HASHTABLE === $expected
            && 0 !== ($var->type & Variable::IS_NATIVE_ARRAY)
        ) {
            return;
        }
        if (Variable::TYPE_VALUE === $var->type) {
            self::enforceExactValueBox($context, $var, $expected);

            return;
        }
        $context->builder->call($context->lookupFunction('abort'));
    }

    /**
     * Weak-mode coerce a trailing variadic element to the declared scalar type (#26587).
     *
     * @return Variable|null coerced value, or null when coercion is impossible (TypeError)
     */
    public static function coerceParameterWeak(
        Context $context,
        Variable $var,
        int $vmConstraint
    ): ?Variable {
        $expected = Variable::fromVMVariable($vmConstraint);
        if ($var->type === $expected) {
            return $var;
        }
        if (
            Variable::TYPE_HASHTABLE === $expected
            && 0 !== ($var->type & Variable::IS_NATIVE_ARRAY)
        ) {
            return $var;
        }
        switch ($expected) {
            case Variable::TYPE_STRING:
                try {
                    return JitNativeString::coerce($context, $var);
                } catch (\LogicException $e) {
                    return null;
                }
            case Variable::TYPE_NATIVE_LONG:
                try {
                    if (Variable::TYPE_NATIVE_DOUBLE === $var->type) {
                        return new Variable(
                            $context,
                            Variable::TYPE_NATIVE_LONG,
                            Variable::KIND_VALUE,
                            \PHPCompiler\ext\standard\JitIntdiv::floatToLongTypedSafe(
                                $context,
                                $context->helper->loadValue($var),
                                'Argument must be of type int, float given'
                            )
                        );
                    }

                    return new Variable(
                        $context,
                        Variable::TYPE_NATIVE_LONG,
                        Variable::KIND_VALUE,
                        JitZendScalarCast::emitIntCast($context, $var)
                    );
                } catch (\LogicException $e) {
                    return null;
                }
            case Variable::TYPE_NATIVE_DOUBLE:
                try {
                    return new Variable(
                        $context,
                        Variable::TYPE_NATIVE_DOUBLE,
                        Variable::KIND_VALUE,
                        JitZendScalarCast::emitFloatCast($context, $var)
                    );
                } catch (\LogicException $e) {
                    return null;
                }
            case Variable::TYPE_NATIVE_BOOL:
                try {
                    return new Variable(
                        $context,
                        Variable::TYPE_NATIVE_BOOL,
                        Variable::KIND_VALUE,
                        JitBoolArg::lowerCoerce($context, $var, 'Argument')
                    );
                } catch (\LogicException $e) {
                    return null;
                }
            default:
                // Non-scalar constraints (array/object) — leave unchanged; other enforcers apply.
                if (VMVariable::TYPE_ARRAY === $vmConstraint || VMVariable::TYPE_OBJECT === $vmConstraint) {
                    return $var;
                }

                return null;
        }
    }

    private static function enforceExactValueBox(Context $context, Variable $var, int $expected): void
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'strict_type_ok');
        $failBlock = BasicBlockHelper::append($context, 'strict_type_fail');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt($expected, false)),
            $okBlock,
            $failBlock
        );
        $context->builder->positionAtEnd($failBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }
}
