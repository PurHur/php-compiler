<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
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
        // `saveXML(options: …)` must not treat int arg #1 as $node (#32018 / #25182).
        [$nodeArg] = JitDomSaveSerializationArgs::parse($args);
        if (JitDomSaveSerializationArgs::isNodeScoped($nodeArg)) {
            $serialized = self::trySerializeNode($context, $nodeArg);
            if (null !== $serialized) {
                return $serialized;
            }

            // DomRegistry / non-pure load: never substitute the compile-time document literal
            // for a node-scoped save — fall through to DomSaveXMLRuntime (#26757).
            return null;
        }

        $xmlLit = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xmlLit || '' === trim($xmlLit)) {
            return null;
        }
        // Document-wide constant replay is only valid for pure user-script loads (#26757).
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
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
        // null / omitted mean document-wide save (#25182). documentElement temps are often
        // TYPE_VALUE after assign — still serialize from slots (#25271 / re-#23892).
        if (!\in_array($nodeVar->type, [JITVariable::TYPE_OBJECT, JITVariable::TYPE_VALUE], true)) {
            return null;
        }
        $xmlLit = JitDomLoadXMLUserScript::lastCompileTimeXml();
        $useXmlLitTag = JitDomLoadXMLUserScript::lastLoadWasPureUserScript() && null !== $xmlLit;
        $node = self::loadObjectArg($context, $nodeVar);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, 'textContent')) {
            $objectType->defineProperty($elementClassId, 'textContent', JITVariable::TYPE_STRING);
        }
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_USER_SCRIPT_INNER_XML)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_USER_SCRIPT_INNER_XML, JITVariable::TYPE_STRING);
        }
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_USER_SCRIPT_XMLNS_ATTR)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_USER_SCRIPT_XMLNS_ATTR, JITVariable::TYPE_STRING);
        }
        if (!$objectType->hasProperty($elementClassId, 'tagName')) {
            $objectType->defineProperty($elementClassId, 'tagName', JITVariable::TYPE_STRING);
        }
        // loadXML documentElement temps often lose DOMElement type (#23251). createElement
        // without loadXML seeds tagName (#32292 / php-src document.c xmlNodeDump).
        if ($useXmlLitTag) {
            $tagStr = $context->builder->load(
                $context->constantStringFromString(DomParseSimpleXmlJitHelper::rootTagArgv((string) $xmlLit))
            );
            $openTagStr = $tagStr;
        } else {
            $tagVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $node,
                'DOMElement',
                'tagName',
                $elementClassId
            );
            $tagStr = $context->helper->loadValue($tagVar);
            // createElementNS nsDef: ` xmlns:prefix="uri"` on the dump root (#32302).
            $xmlnsVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $node,
                'DOMElement',
                VmDom::PROP_USER_SCRIPT_XMLNS_ATTR,
                $elementClassId
            );
            $xmlnsStr = $context->helper->loadValue($xmlnsVar);
            $openTagStr = JitStringConcat::concat($context, $tagStr, $xmlnsStr);
        }
        // Prefer ParentNode append/prepend markup when present (#26765); else textContent
        // (textContent-only mutations / loadXML root text).
        $innerVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            VmDom::PROP_USER_SCRIPT_INNER_XML,
            $elementClassId
        );
        $innerStr = $context->helper->loadValue($innerVar);
        $textVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'textContent',
            $elementClassId
        );
        $textStr = $context->helper->loadValue($textVar);
        // Use inner XML when non-empty; otherwise fall back to textContent.
        $innerLen = $context->builder->load(
            $context->builder->structGep($innerStr, $context->structFieldMap['__string__']['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $hasInner = $context->builder->icmp(Builder::INT_SGT, $innerLen, $zero);
        $bodySlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__string__*')
        );
        $pickInner = BasicBlockHelper::append($context, 'dom_savexml_pick_inner');
        $pickText = BasicBlockHelper::append($context, 'dom_savexml_pick_text');
        $bodyMerge = BasicBlockHelper::append($context, 'dom_savexml_body_merge');
        $context->builder->branchIf($hasInner, $pickInner, $pickText);
        $context->builder->positionAtEnd($pickInner);
        $context->builder->store($innerStr, $bodySlot);
        $context->builder->branch($bodyMerge);
        $context->builder->positionAtEnd($pickText);
        $context->builder->store($textStr, $bodySlot);
        $context->builder->branch($bodyMerge);
        $context->builder->positionAtEnd($bodyMerge);
        $bodyStr = $context->builder->load($bodySlot);
        $lt = $context->builder->load($context->constantStringFromString('<'));
        $gt = $context->builder->load($context->constantStringFromString('>'));
        $ltSlash = $context->builder->load($context->constantStringFromString('</'));
        $slashGt = $context->builder->load($context->constantStringFromString('/>'));
        // Empty body → self-closing `<tag/>` (libxml xmlNodeDump; #29409).
        $bodyLen = $context->builder->load(
            $context->builder->structGep($bodyStr, $context->structFieldMap['__string__']['length'])
        );
        $bodyEmpty = $context->builder->icmp(Builder::INT_EQ, $bodyLen, $zero);
        $bbSelfClose = BasicBlockHelper::append($context, 'dom_savexml_self_close');
        $bbPaired = BasicBlockHelper::append($context, 'dom_savexml_paired');
        $bbXmlMerge = BasicBlockHelper::append($context, 'dom_savexml_xml_merge');
        $xmlSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__string__*')
        );
        $context->builder->branchIf($bodyEmpty, $bbSelfClose, $bbPaired);
        $context->builder->positionAtEnd($bbSelfClose);
        $selfClose = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $lt, $openTagStr),
            $slashGt
        );
        $context->builder->store($selfClose, $xmlSlot);
        $context->builder->branch($bbXmlMerge);
        $context->builder->positionAtEnd($bbPaired);
        $open = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $lt, $openTagStr),
            $gt
        );
        $close = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $ltSlash, $tagStr),
            $gt
        );
        $withBody = JitStringConcat::concat($context, $open, $bodyStr);
        $paired = JitStringConcat::concat($context, $withBody, $close);
        $context->builder->store($paired, $xmlSlot);
        $context->builder->branch($bbXmlMerge);
        $context->builder->positionAtEnd($bbXmlMerge);
        $xml = $context->builder->load($xmlSlot);

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
