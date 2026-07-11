<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomDocumentMethodUserScriptLlvm;
use PHPCompiler\JIT\Builtin\DomLoadHTMLRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Intdiv as JitIntdiv;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::loadHTML() (#17954).
 *
 * php-src: ext/dom/php_dom.c — dom_document_load_html
 */
final class JitDomLoadHTML
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::loadHTML() expects receiver and HTML string');
        }

        if (JitDomLoadHTMLUserScript::shouldUse($context)) {
            return JitDomLoadHTMLUserScript::invoke($context, ...$args);
        }

        $receiverPtr = self::receiverValuePtr($context, $args[0]);
        $htmlStr = self::loadStringArg($context, $args[1]);
        $options = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $options = JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'DOMDocument::loadHTML()', 2, 'options');
        }

        return $context->builder->call(
            $context->lookupFunction(DomLoadHTMLRuntime::ABI_NAME),
            $receiverPtr,
            $htmlStr,
            $options
        );
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }

    private static function receiverValuePtr(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return JitValueBox::valuePtrFromVariable($context, $receiver);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $context->helper->loadValue($receiver)
            );

            return $ptr;
        }

        throw new \LogicException('DOMDocument::loadHTML() receiver must be object or value box');
    }
}
