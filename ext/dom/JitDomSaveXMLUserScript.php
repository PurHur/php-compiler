<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
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
        if (!$objectType->hasProperty($elementClassId, 'nodeName')) {
            $objectType->defineProperty($elementClassId, 'nodeName', JITVariable::TYPE_STRING);
        }
        // createComment/createTextNode seed nodeName but not tagName — fetching tagName
        // SIGSEGVs (#32315). libxml xmlNodeDump: comment `<!--data-->`, text = data.
        // Skip when loadXML supplies the root tag from the compile-time literal (#23251).
        if (!$useXmlLitTag) {
            return self::serializeUserScriptNode($context, $objectType, $node, $elementClassId);
        }

        return self::serializeElementNode(
            $context,
            $objectType,
            $node,
            $elementClassId,
            true,
            (string) $xmlLit
        );
    }

    /**
     * Dump createComment/createTextNode/createProcessingInstruction (and createElement)
     * without a loadXML tag literal.
     *
     * php-src: ext/dom/document.c saveXML → xmlNodeDump (#32315 / #32331)
     */
    private static function serializeUserScriptNode(
        Context $context,
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        Value $node,
        int $elementClassId
    ): Value {
        $nameVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'nodeName',
            $elementClassId
        );
        $nameStr = $context->helper->loadValue($nameVar);
        $textVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'textContent',
            $elementClassId
        );
        $textStr = $context->helper->loadValue($textVar);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $commentLit = $context->builder->load($context->constantStringFromString('#comment'));
        $isComment = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $nameStr, $commentLit),
            $zero
        );
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $bbComment = BasicBlockHelper::append($context, 'dom_savexml_comment');
        $bbCheckText = BasicBlockHelper::append($context, 'dom_savexml_check_text');
        $bbText = BasicBlockHelper::append($context, 'dom_savexml_text');
        $bbCdataCheck = BasicBlockHelper::append($context, 'dom_savexml_check_cdata');
        $bbCdata = BasicBlockHelper::append($context, 'dom_savexml_cdata');
        $bbPiCheck = BasicBlockHelper::append($context, 'dom_savexml_check_pi');
        $bbPi = BasicBlockHelper::append($context, 'dom_savexml_pi');
        $bbPiEmpty = BasicBlockHelper::append($context, 'dom_savexml_pi_empty');
        $bbPiData = BasicBlockHelper::append($context, 'dom_savexml_pi_data');
        $bbElement = BasicBlockHelper::append($context, 'dom_savexml_element');
        $bbDone = BasicBlockHelper::append($context, 'dom_savexml_leaf_done');
        $context->builder->branchIf($isComment, $bbComment, $bbCheckText);

        $context->builder->positionAtEnd($bbComment);
        $commentOpen = $context->builder->load($context->constantStringFromString('<!--'));
        $commentClose = $context->builder->load($context->constantStringFromString('-->'));
        $commentXml = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $commentOpen, $textStr),
            $commentClose
        );
        $context->builder->store(self::boxStringValue($context, $commentXml), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckText);
        $textLit = $context->builder->load($context->constantStringFromString('#text'));
        $isText = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $nameStr, $textLit),
            $zero
        );
        $context->builder->branchIf($isText, $bbText, $bbCdataCheck);

        $context->builder->positionAtEnd($bbText);
        $context->builder->store(self::boxStringValue($context, $textStr), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCdataCheck);
        $cdataLit = $context->builder->load($context->constantStringFromString('#cdata-section'));
        $isCdata = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $nameStr, $cdataLit),
            $zero
        );
        $context->builder->branchIf($isCdata, $bbCdata, $bbPiCheck);

        $context->builder->positionAtEnd($bbCdata);
        $cdataOpen = $context->builder->load($context->constantStringFromString('<![CDATA['));
        $cdataClose = $context->builder->load($context->constantStringFromString(']]>'));
        $cdataXml = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $cdataOpen, $textStr),
            $cdataClose
        );
        $context->builder->store(self::boxStringValue($context, $cdataXml), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbPiCheck);
        $tagVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'tagName',
            $elementClassId
        );
        $tagStr = $context->helper->loadValue($tagVar);
        $piKindLit = $context->builder->load(
            $context->constantStringFromString(JitDomCreateProcessingInstruction::TAG_KIND)
        );
        $isPi = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $tagStr, $piKindLit),
            $zero
        );
        $context->builder->branchIf($isPi, $bbPi, $bbElement);

        $context->builder->positionAtEnd($bbPi);
        // libxml xmlNodeDump PI: lt-query + target + optional space+data + query-gt (#32331).
        $emptyLit = $context->builder->load($context->constantStringFromString(''));
        $dataEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $textStr, $emptyLit),
            $zero
        );
        $context->builder->branchIf($dataEmpty, $bbPiEmpty, $bbPiData);

        $context->builder->positionAtEnd($bbPiEmpty);
        $piOpen = $context->builder->load($context->constantStringFromString('<?'));
        $piClose = $context->builder->load($context->constantStringFromString('?>'));
        $piEmptyXml = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $piOpen, $nameStr),
            $piClose
        );
        $context->builder->store(self::boxStringValue($context, $piEmptyXml), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbPiData);
        $piOpenData = $context->builder->load($context->constantStringFromString('<?'));
        $piSpace = $context->builder->load($context->constantStringFromString(' '));
        $piCloseData = $context->builder->load($context->constantStringFromString('?>'));
        $piDataXml = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat($context, $piOpenData, $nameStr),
                    $piSpace
                ),
                $textStr
            ),
            $piCloseData
        );
        $context->builder->store(self::boxStringValue($context, $piDataXml), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbElement);
        $elemXml = self::serializeElementNode(
            $context,
            $objectType,
            $node,
            $elementClassId,
            false,
            null
        );
        $context->builder->store($elemXml, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($resultSlot);
    }

    /**
     * Element dump: `<tag xmlns?>body</tag>` or self-closing `<tag/>`.
     *
     * loadXML documentElement temps often lose DOMElement type (#23251). createElement
     * without loadXML seeds tagName (#32292 / php-src document.c xmlNodeDump).
     */
    private static function serializeElementNode(
        Context $context,
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        Value $node,
        int $elementClassId,
        bool $useXmlLitTag,
        ?string $xmlLit
    ): Value {
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
