<?php

declare(strict_types=1);

/**
 * Lower string/path builtin arguments to {@see __string__*} for LLVM calls.
 *
 * Self-host bundle code often passes concat/__DIR__ paths as boxed {@see Variable::TYPE_VALUE}
 * (string tag) rather than {@see Variable::TYPE_STRING}.
 */

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

final class JitStringArg
{
    /** @return Value */
    public static function lower(Context $context, Variable $arg, string $contextLabel = 'argument'): Value
    {
        if (\in_array($arg->type, [
            Variable::TYPE_NATIVE_LONG,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::TYPE_NATIVE_BOOL,
        ], true)) {
            $coerced = JitNativeString::coerce($context, $arg);

            return $context->helper->loadValue($coerced);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }
        if (Variable::TYPE_STRING === $arg->type && Variable::KIND_VARIABLE === $arg->kind) {
            return $context->helper->loadValue($arg);
        }
        $literal = self::compileTimeLiteral($arg);
        if (null !== $literal) {
            if (Variable::TYPE_STRING === $arg->type && Variable::KIND_VALUE === $arg->kind) {
                return $context->helper->loadValue($arg);
            }

            return $context->builder->load($context->constantStringFromString($literal));
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }
        if (Variable::TYPE_HASHTABLE === $arg->type) {
            // FuncCall->args and similar may be lowered as hashtable pointers (issue #816).
            $ht = $context->helper->loadValue($arg);

            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $context->builder->pointerCast(
                    $ht,
                    $context->getTypeFromString('__value__*')
                )
            );
        }

        throw new \LogicException("{$contextLabel} must be a string in this compiler build");
    }

    /**
     * Lower to {@see __string__*} with an entry-block scratch slot so uses dominate CFG branches (#4066).
     *
     * @return Value
     */
    public static function lowerDominating(Context $context, Variable $arg, string $contextLabel = 'argument'): Value
    {
        $literal = self::compileTimeLiteral($arg);
        if (null !== $literal) {
            return $context->builder->load($context->constantStringFromString($literal));
        }
        if (Variable::TYPE_STRING === $arg->type && Variable::KIND_VARIABLE === $arg->kind) {
            return self::materializeStringSlot($context, $context->helper->loadValue($arg));
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $str = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );

            return self::materializeStringSlot($context, $str);
        }

        return self::lower($context, $arg, $contextLabel);
    }

    /** @return Value owning {@see __string__*} in an entry alloca */
    private static function materializeStringSlot(Context $context, Value $sourceStr): Value
    {
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $sourceStr
        );
        $context->builder->store($owned, $slot);

        return $context->builder->load($slot);
    }

    public static function compileTimeLiteral(Variable $arg): ?string
    {
        return $arg->compileTimeString ?? null;
    }
}
