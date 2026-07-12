<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomSaveXMLRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMDocument::saveXML() (#18268). */
final class JitDomSaveXML
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('DOMDocument::saveXML() expects receiver');
        }

        if (JitDomSaveXMLUserScript::shouldUse($context)) {
            $us = JitDomSaveXMLUserScript::tryInvoke($context);
            if (null !== $us) {
                return $us;
            }
        }

        DomSaveXMLRuntime::ensureLinked($context);

        return self::boxStringResult(
            $context,
            $context->builder->call(
                $context->lookupFunction(DomSaveXMLRuntime::ABI_NAME),
                self::loadObjectArg($context, $args[0])
            )
        );
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

        throw new \LogicException('DOMDocument::saveXML() receiver must be an object');
    }
}
