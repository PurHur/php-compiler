<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomC14NRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMNode::C14N() (#19467). */
final class JitDomC14N
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DOMNode::C14N() expects receiver');
        }
        DomC14NRuntime::ensureLinked($context);
        $exclusive = self::exclusiveAsI64($context, $args[1] ?? null);
        $str = $context->builder->call(
            $context->lookupFunction(DomC14NRuntime::ABI_NAME),
            self::loadObjectArg($context, $args[0]),
            $exclusive
        );

        return self::boxStringResult($context, $str);
    }

    private static function exclusiveAsI64(Context $context, ?JITVariable $arg): Value
    {
        if (null === $arg) {
            return $context->context->int64Type()->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            $raw = $context->helper->loadValue($arg);
            if (method_exists($raw, 'isConstant') && $raw->isConstant() && method_exists($raw, 'getConstantValue')) {
                return $context->context->int64Type()->constInt(
                    ((int) $raw->getConstantValue() !== 0) ? 1 : 0,
                    false
                );
            }

            return $context->builder->zExt($raw, $context->context->int64Type());
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== $arg->compileTimeLong) {
            return $context->context->int64Type()->constInt(0 !== $arg->compileTimeLong ? 1 : 0, false);
        }

        // Non-constant exclusive flag: default inclusive (0). Issue repros use literal true/false.
        return $context->context->int64Type()->constInt(0, false);
    }

    private static function boxStringResult(Context $context, Value $str): Value
    {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMNode::C14N() receiver must be an object');
    }
}
