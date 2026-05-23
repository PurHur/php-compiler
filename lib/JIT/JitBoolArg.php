<?php
declare(strict_types=1);
namespace PHPCompiler\JIT;
use PHPLLVM\Value;
final class JitBoolArg {
    public static function lower(Context $context, Variable $arg, string $contextLabel = "argument"): Value {
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) return $context->helper->loadValue($arg);
        if (Variable::TYPE_NATIVE_LONG === $arg->type) return $context->builder->truncOrBitCast($context->helper->loadValue($arg), $context->getTypeFromString("int1"));
        if (Variable::TYPE_VALUE === $arg->type) return $context->builder->truncOrBitCast($context->builder->call($context->lookupFunction("__value__readLong"), JitValueBox::valuePtrFromVariable($context, $arg)), $context->getTypeFromString("int1"));
        if (Variable::TYPE_NULL === $arg->type) return $context->constantFromBool(false);
        throw new \LogicException("{$contextLabel} must be a boolean in this compiler build");
    }
}
