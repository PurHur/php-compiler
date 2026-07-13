<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomGetElementsByTagNameRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::getElementsByTagName() (#18461).
 *
 * php-src: ext/dom/php_dom.c — dom_document_get_elements_by_tag_name
 */
final class JitDomGetElementsByTagName
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::getElementsByTagName() expects receiver and tag name');
        }

        DomGetElementsByTagNameRuntime::ensureLinked($context);

        $document = self::loadObjectArg($context, $args[0]);
        $tagArg = self::tagNameValuePtr($context, $args[1]);

        return $context->builder->call(
            $context->lookupFunction(DomGetElementsByTagNameRuntime::ABI_NAME),
            $document,
            $tagArg
        );
    }

    private static function tagNameValuePtr(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $slot),
                $context->helper->loadValue($arg)
            );

            return JitValueBox::normalizeValuePtr($context, JitValueBox::pointer($context, $slot));
        }

        return JitValueBox::valuePtrFromVariable($context, $arg);
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

        throw new \LogicException('DOMDocument::getElementsByTagName() receiver must be an object');
    }
}
