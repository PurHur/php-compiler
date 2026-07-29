<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomSaveHTMLRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMDocument::saveHTML() (#18268, #24580). */
final class JitDomSaveHTML
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('DOMDocument::saveHTML() expects receiver');
        }

        if (JitDomSaveHTMLUserScript::shouldUse($context)) {
            return JitDomSaveHTMLUserScript::invoke($context, ...$args);
        }

        DomSaveHTMLRuntime::ensureLinked($context);

        return self::boxStringResult(
            $context,
            $context->builder->call(
                $context->lookupFunction(DomSaveHTMLRuntime::ABI_NAME),
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

        throw new \LogicException('DOMDocument::saveHTML() receiver must be an object');
    }
}
