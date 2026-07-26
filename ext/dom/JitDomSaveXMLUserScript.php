<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script standalone AOT: pure-LLVM DOMDocument::saveXML() (#18268, #23251). */
final class JitDomSaveXMLUserScript
{
    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    /**
     * @param JITVariable ...$args document [, node]
     */
    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        // saveXML($node) after textContent mutation: serialize from node slots (#23251).
        if (\count($args) >= 2 && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $serialized = self::trySerializeNode($context, $args[1]);
            if (null !== $serialized) {
                return $serialized;
            }
        }

        $xmlLit = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xmlLit || '' === trim($xmlLit)) {
            return null;
        }

        $trimmed = trim($xmlLit);
        $out = str_starts_with($trimmed, '<?xml')
            ? $trimmed."\n"
            : '<?xml version="1.0"?>'."\n".$trimmed."\n";

        return self::boxConstantString($context, $out);
    }

    private static function trySerializeNode(Context $context, JITVariable $nodeVar): ?Value
    {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return null;
        }
        $xmlLit = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xmlLit) {
            return null;
        }
        $node = self::loadObjectArg($context, $nodeVar);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, 'textContent')) {
            $objectType->defineProperty($elementClassId, 'textContent', JITVariable::TYPE_STRING);
        }
        // Prefer compile-time root tag — documentElement temps often lose DOMElement type
        // so runtime tagName reads can see an empty dynamic slot (#23251).
        $tagStr = $context->builder->load(
            $context->constantStringFromString(DomParseSimpleXmlJitHelper::rootTagArgv($xmlLit))
        );
        $textVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'textContent',
            $elementClassId
        );
        $textStr = $context->helper->loadValue($textVar);
        $lt = $context->builder->load($context->constantStringFromString('<'));
        $gt = $context->builder->load($context->constantStringFromString('>'));
        $ltSlash = $context->builder->load($context->constantStringFromString('</'));
        $open = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $lt, $tagStr),
            $gt
        );
        $close = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $ltSlash, $tagStr),
            $gt
        );
        $withText = JitStringConcat::concat($context, $open, $textStr);
        $xml = JitStringConcat::concat($context, $withText, $close);

        return self::boxStringValue($context, $xml);
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

        throw new \LogicException('DOMDocument::saveXML() node must be an object');
    }

    private static function boxConstantString(Context $context, string $lit): Value
    {
        $str = $context->builder->load($context->constantStringFromString($lit));

        return self::boxStringValue($context, $str);
    }

    private static function boxStringValue(Context $context, Value $str): Value
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
}
