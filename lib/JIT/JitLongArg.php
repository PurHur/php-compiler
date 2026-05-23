<?php
declare(strict_types=1);
namespace PHPCompiler\JIT;
use PHPLLVM\Value;
final class JitLongArg {
    public static function lower(Context $context, Variable $arg, string $contextLabel = "argument"): Value {
        if (Variable::TYPE_NATIVE_LONG === $arg->type) return $context->helper->loadValue($arg);
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) return $context->builder->zExt($context->helper->loadValue($arg), $context->getTypeFromString("int64"));
        if (Variable::TYPE_VALUE === $arg->type) return $context->builder->call($context->lookupFunction("__value__readLong"), JitValueBox::valuePtrFromVariable($context, $arg));
        if (Variable::TYPE_NULL === $arg->type) return $context->getTypeFromString("int64")->constInt(0, false);
        if (Variable::TYPE_OBJECT === $arg->type) return $context->builder->ptrToInt($context->helper->loadValue($arg), $context->getTypeFromString("int64"));
        throw new \LogicException("{$contextLabel} must be an integer in this compiler build");
    }
}
