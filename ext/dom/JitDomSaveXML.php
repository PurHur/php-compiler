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
            $us = JitDomSaveXMLUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        DomSaveXMLRuntime::ensureLinked($context);

        [$nodeArg] = JitDomSaveSerializationArgs::parse($args);
        $bridgeArgs = [self::loadObjectArg($context, $args[0])];
        if (JitDomSaveSerializationArgs::isNodeScoped($nodeArg)) {
            $bridgeArgs[] = JitValueBox::valuePtrFromVariable($context, $nodeArg);
        } else {
            $nullSlot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $nullSlot)
            );
            $bridgeArgs[] = JitValueBox::normalizeValuePtr($context, $nullSlot);
        }

        return self::boxStringResult(
            $context,
            $context->builder->call(
                $context->lookupFunction(DomSaveXMLRuntime::ABI_NAME),
                ...$bridgeArgs
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
