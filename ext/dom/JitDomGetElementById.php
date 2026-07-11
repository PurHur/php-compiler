<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomGetElementByIdRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::getElementById() (#17954).
 *
 * php-src: ext/dom/php_dom.c — dom_document_get_element_by_id
 */
final class JitDomGetElementById
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::getElementById() expects receiver and element id');
        }

        $document = self::loadObjectArg($context, $args[0]);
        $elementId = self::loadStringArg($context, $args[1]);

        return $context->builder->call(
            $context->lookupFunction(DomGetElementByIdRuntime::ABI_NAME),
            $document,
            $elementId
        );
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

        throw new \LogicException('DOMDocument::getElementById() receiver must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return JitStringBuiltinArg::lower($context, $arg, 'DOMDocument::getElementById()', 0, 'elementId');
    }
}
