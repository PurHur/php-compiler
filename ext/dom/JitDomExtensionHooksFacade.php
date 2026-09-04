<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\DomExtensionHooks;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomC14NRuntime;
use PHPCompiler\JIT\Builtin\DomC14NFileRuntime;
use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Builtin\DomNodeChildNodeMutationRuntime;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\Builtin\VmClassMethod;

/**
 * dom surfaces for lib/JIT Call Dom* thin proxies (#36204).
 *
 * php-src: ext/dom/*.c — DOM* method thin-AOT kernels.
 * Registered from {@see Module::jitInit} so Call files do not import ext/dom.
 */
final class JitDomExtensionHooksFacade implements DomExtensionHooks
{
    public function invokeCall(Context $context, string $callId, JITVariable ...$args): Value
    {
        return match ($callId) {
            'attr.rename' => JitDomAttrRename::invoke($context, ...$args),
            'characterData.appendData' => JitDomAppendData::invoke($context, ...$args),
            'characterData.deleteData' => JitDomDeleteData::invoke($context, ...$args),
            'characterData.insertData' => JitDomInsertData::invoke($context, ...$args),
            'characterData.replaceData' => JitDomReplaceData::invoke($context, ...$args),
            'characterData.substringData' => JitDomSubstringData::invoke($context, ...$args),
            'document.adoptNode' => JitDomAdoptNode::invoke($context, ...$args),
            'document.createAttribute' => JitDomAttributeNodeNS::invokeCreateAttribute($context, ...$args),
            'document.createAttributeNS' => JitDomAttributeNodeNS::invokeCreate($context, ...$args),
            'document.createCDATASection' => JitDomCreateCDATASection::invoke($context, ...$args),
            'document.createComment' => JitDomCreateComment::invoke($context, ...$args),
            'document.createDocumentFragment' => JitDomCreateDocumentFragment::invoke($context, ...$args),
            'document.createElement' => JitDomCreateElement::invoke($context, ...$args),
            'document.createElementNS' => JitDomCreateElementNS::invoke($context, ...$args),
            'document.createEntityReference' => JitDomCreateEntityReference::invoke($context, ...$args),
            'document.createProcessingInstruction' => JitDomCreateProcessingInstruction::invoke($context, ...$args),
            'document.createTextNode' => JitDomCreateTextNode::invoke($context, ...$args),
            'document.importNode' => JitDomImportNode::invoke($context, ...$args),
            'document.loadHTMLFile' => JitDomLoadHTMLFile::invoke($context, ...$args),
            'document.loadXML' => JitDomLoadXML::invoke($context, ...$args),
            'document.save' => JitDomSave::invoke($context, ...$args),
            'document.saveHTML' => JitDomSaveHTML::invoke($context, ...$args),
            'document.saveHTMLFile' => JitDomSaveHTMLFile::invoke($context, ...$args),
            'document.saveXML' => JitDomSaveXML::invoke($context, ...$args),
            'element.getAttributeNode' => JitDomAttributeNodeNS::invokeGetAttributeNode($context, ...$args),
            'element.getAttributeNodeNS' => JitDomAttributeNodeNS::invokeGet($context, ...$args),
            'element.insertAdjacentElement' => JitDomInsertAdjacent::invokeElement($context, ...$args),
            'element.insertAdjacentText' => JitDomInsertAdjacent::invokeText($context, ...$args),
            'element.setIdAttribute' => JitDomSetIdAttribute::invoke($context, ...$args),
            'element.setIdAttributeNS' => JitDomSetIdAttribute::invokeNs($context, ...$args),
            'htmlDocument.createFromFile' => JitDomHtmlDocumentCreateFromFile::invoke($context, ...$args),
            'htmlDocument.createFromString' => JitDomHtmlDocumentCreateFromString::invoke($context, ...$args),
            'htmlDocument.saveHtml' => JitDomHtmlDocumentSaveHtml::invoke($context, ...$args),
            'implementation.createDocument' => JitDomCreateDocument::invoke($context, ...$args),
            'implementation.createDocumentType' => JitDomCreateDocumentType::invoke($context, ...$args),
            'implementation.hasFeature' => JitDomIsSupported::invokeHasFeature($context, ...$args),
            'livingDocument.createElement' => JitDomCreateElement::invokeLiving($context, ...$args),
            'livingDocument.createElementNS' => JitDomCreateElementNS::invokeLiving($context, ...$args),
            'namedNodeMap.getNamedItem' => JitDomNamedNodeMap::invokeGetNamedItem($context, ...$args),
            'namedNodeMap.getNamedItemNS' => JitDomNamedNodeMap::invokeGetNamedItemNS($context, ...$args),
            'namedNodeMap.item' => JitDomNamedNodeMap::invokeItem($context, ...$args),
            'node.cloneNode' => JitDomCloneNode::invoke($context, ...$args),
            'node.getLineNo' => JitDomGetLineNo::invoke($context, ...$args),
            'node.getNodePath' => JitDomGetNodePath::invoke($context, ...$args),
            'node.hasAttributes' => JitDomHasAttributes::invoke($context, ...$args),
            'node.hasChildNodes' => JitDomHasChildNodes::invoke($context, ...$args),
            'node.insertBefore' => JitDomInsertBefore::invoke($context, ...$args),
            'node.isDefaultNamespace' => JitDomLookupNamespaceURI::invokeIsDefaultNamespace($context, ...$args),
            'node.isSupported' => JitDomIsSupported::invoke($context, ...$args),
            'nodeList.item' => JitDomNodeListItem::invoke($context, ...$args),
            'node.lookupNamespaceURI' => JitDomLookupNamespaceURI::invoke($context, ...$args),
            'node.lookupPrefix' => JitDomLookupPrefix::invoke($context, ...$args),
            'node.removeChild' => JitDomRemoveChild::invoke($context, ...$args),
            'node.replaceChild' => JitDomReplaceChild::invoke($context, ...$args),
            'text.isWhitespaceInElementContent' => JitDomIsWhitespaceInElementContent::invoke($context, ...$args),
            'text.splitText' => JitDomSplitText::invoke($context, ...$args),
            'xpath.evaluate' => JitDomXPathEvaluate::invoke($context, ...$args),
            'xpath.query' => JitDomXPathQuery::invoke($context, ...$args),
            'xmlDocument.createFromFile' => JitDomXmlDocumentCreateFromFile::invoke($context, ...$args),
            'xmlDocument.createFromString' => JitDomXmlDocumentCreateFromString::invoke($context, ...$args),
            'document.normalizeDocument' => $this->invokeDocumentNormalizeDocument($context, ...$args),
            'document.getElementById' => $this->invokeDocumentGetElementById($context, ...$args),
            'document.getElementsByTagName' => $this->invokeDocumentGetElementsByTagName($context, ...$args),
            'document.getElementsByTagNameNS' => $this->invokeDocumentGetElementsByTagNameNS($context, ...$args),
            'element.getElementsByTagName' => $this->invokeElementGetElementsByTagName($context, ...$args),
            'element.getElementsByTagNameNS' => $this->invokeElementGetElementsByTagNameNS($context, ...$args),
            'node.C14N' => $this->invokeNodeC14N($context, ...$args),
            'node.C14NFile' => $this->invokeNodeC14NFile($context, ...$args),
            'node.normalize' => $this->invokeNodeNormalize($context, ...$args),
            'element.setIdAttributeNode' => $this->invokeElementSetIdAttributeNode($context, ...$args),
            'element.setAttributeNode' => $this->invokeElementSetAttributeNode($context, ...$args),
            'element.setAttributeNodeNS' => $this->invokeElementSetAttributeNodeNS($context, ...$args),
            'element.removeAttributeNode' => $this->invokeElementRemoveAttributeNode($context, ...$args),
            'document.appendChild' => $this->invokeDocumentAppendChild($context, ...$args),
            'element.hasAttribute' => $this->invokeElementHasAttribute($context, ...$args),
            'element.hasAttributeNS' => $this->invokeElementHasAttributeNS($context, ...$args),
            'node.remove' => $this->invokeNodeRemove($context, ...$args),
            'xpath.registerPhpFunctions' => $this->invokeXpathRegisterPhpFunctions($context, ...$args),
            'xpath.registerNamespace' => $this->invokeXpathRegisterNamespace($context, ...$args),
            default => throw new \LogicException(
                'Unknown DOM Call kernel id: '.$callId.' (#36204)'
            ),
        };
    }

    /** DOMDocument::normalizeDocument() — user-script AOT (#20642). */
    private function invokeDocumentNormalizeDocument(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_document_invoke_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMDocument::normalizeDocument',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return JitDomNormalize::invokeDocument($context, ...$args);
    }

    /** DOMDocument::getElementById() — user-script AOT (#17954). */
    private function invokeDocumentGetElementById(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gei_call_cont');

        $result = JitDomGetElementById::invoke($context, ...$args);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gei_post_invoke');

        return $result;
    }

    /** DOMDocument::getElementsByTagName() — user-script AOT (#18461, #18478). */
    private function invokeDocumentGetElementsByTagName(Context $context, JITVariable ...$args): Value
    {

        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMDocument::getElementsByTagName',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return JitDomGetElementsByTagName::invoke($context, ...$args);
    }

    /** DOMDocument::getElementsByTagNameNS() — user-script AOT (#32415, php-src php_dom.c). */
    private function invokeDocumentGetElementsByTagNameNS(Context $context, JITVariable ...$args): Value
    {

        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMDocument::getElementsByTagNameNS',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gebtns_invoke_cont');

        return JitDomGetElementsByTagName::invokeNS($context, ...$args);
    }

    /** DOMElement::getElementsByTagName() — user-script AOT (#32454, ext/dom/element.c). */
    private function invokeElementGetElementsByTagName(Context $context, JITVariable ...$args): Value
    {

        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMElement::getElementsByTagName',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return JitDomGetElementsByTagName::invokeFromElement($context, ...$args);
    }

    /** DOMElement::getElementsByTagNameNS() — user-script AOT (#32511, ext/dom/element.c). */
    private function invokeElementGetElementsByTagNameNS(Context $context, JITVariable ...$args): Value
    {

        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMElement::getElementsByTagNameNS',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_el_gebtns_invoke_cont');

        return JitDomGetElementsByTagName::invokeFromElementNS($context, ...$args);
    }

    /** DOMNode::C14N() — user-script AOT (#19467). */
    private function invokeNodeC14N(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_c14n_invoke_cont');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMNode::C14N',
            0,
            4
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        DomC14NRuntime::ensureLinked($context);

        return JitDomC14N::invoke($context, ...$args);
    }

    /** DOMNode::C14NFile() — user-script AOT (#32964; peer DomNodeC14N #19467). */
    private function invokeNodeC14NFile(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_c14nfile_invoke_cont');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMNode::C14NFile',
            1,
            5
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        DomC14NFileRuntime::ensureLinked($context);

        return JitDomC14NFile::invoke($context, ...$args);
    }

    /** DOMNode::normalize() — user-script AOT (#20642). */
    private function invokeNodeNormalize(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_invoke_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNode::normalize',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return JitDomNormalize::invoke($context, ...$args);
    }

    /** DOMElement::setIdAttributeNode() — user-script AOT (#29284, #33758). */
    private function invokeElementSetIdAttributeNode(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setidattributenode_invoke_cont');

        // Skip id-map cache sync after null TypeError (#33758 / peer #33753).
        if (\count($args) >= 2
            && JitDomRequireDomNodeArg::guardOrAbort(
                $context,
                $args[1],
                'DOMElement::setIdAttributeNode',
                1,
                'attr',
                'DOMAttr'
            )
        ) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        return JitDomSetIdAttribute::invokeNode($context, ...$args);
    }

    /** DOMElement::setAttributeNode() — user-script AOT (#20676, #33570). */
    private function invokeElementSetAttributeNode(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrnode_invoke_cont');

        // Skip saveXML sync after null TypeError — avoids module-verify drift (#33753).
        if (\count($args) >= 2
            && JitDomRequireDomNodeArg::guardOrAbort(
                $context,
                $args[1],
                'DOMElement::setAttributeNode',
                1,
                'attr',
                'DOMAttr'
            )
        ) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        $result = JitDomAttributeNodeNS::invokeSetAttributeNode($context, ...$args);
        // saveXML / INNER_XML rebuild read PROP_USER_SCRIPT_XMLNS_ATTR (#33509 / #33570).
        if (\count($args) >= 2) {
            JitDomAttributeNodeNS::syncSaveXmlAttrSuffixAfterSetAttributeNode($context, $args[0]);
        }

        return $result;
    }

    /** DOMElement::setAttributeNodeNS() — user-script AOT (#19265, #33578). */
    private function invokeElementSetAttributeNodeNS(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrnodens_invoke_cont');

        // Skip saveXML sync after null TypeError — peer setAttributeNode (#33753).
        if (\count($args) >= 2
            && JitDomRequireDomNodeArg::guardOrAbort(
                $context,
                $args[1],
                'DOMElement::setAttributeNodeNS',
                1,
                'attr',
                'DOMAttr'
            )
        ) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        $result = JitDomAttributeNodeNS::invokeSet($context, ...$args);
        // saveXML / INNER_XML rebuild read PROP_USER_SCRIPT_XMLNS_ATTR (#33526 / #33578).
        if (\count($args) >= 2) {
            JitDomAttributeNodeNS::syncSaveXmlAttrSuffixAfterSetAttributeNode($context, $args[0]);
        }

        return $result;
    }

    /** DOMElement::removeAttributeNode() — user-script AOT (php-src element.c; #33577 / #34579). */
    private function invokeElementRemoveAttributeNode(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_removeattrnode_invoke_cont');

        // Early-return on null TypeError so saveXML sync is not emitted (#33753 verify).
        if (\count($args) >= 2
            && JitDomRequireDomNodeArg::guardOrAbort(
                $context,
                $args[1],
                'DOMElement::removeAttributeNode',
                1,
                'attr',
                'DOMAttr'
            )
        ) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        $result = JitDomAttributeNodeNS::invokeRemoveAttributeNode($context, ...$args);

        // Drop attr from saveXML open-tag suffix (peer removeAttribute #33509 / #33577 / #34579).
        if (\count($args) >= 1) {
            JitDomAttributeNodeNS::syncSaveXmlAttrSuffixAfterRemoveAttributeNode($context, $args[0]);
        }

        return $result;
    }

    /** DOMDocument::appendChild() — parentNode + documentElement + reparent (#18927, #21687, #27410). */
    private function invokeDocumentAppendChild(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_ac_invoke_cont');
        if (\count($args) >= 2 && JitDomRequireDomNodeArg::guardOrAbort($context, $args[1], 'DOMNode::appendChild', 1, 'node')) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }
        // DocumentFragment: move children onto the document (php-src fragment expand).
        // LiveSlots expand targets Element parents; Document uses UserScript (#33564).
        // createDocumentType → appendChild: stamp doctype for saveXML (#33584).
        if (($args[1]->compileTimeDomTagName ?? null) === \PHPCompiler\ext\dom\JitDomCreateDocumentType::TAG_KIND) {
            DomUserScriptDoctypeLlvm::markAttached();
        }

        return JitDomAppendChildUserScript::invokeDocumentAppendMaybeFragment(
            $context,
            $args[0],
            $args[1]
        );
    }

    /** Dom\Element::hasAttribute() — thin user-script AOT live Attr cache (#27108, #33762). */
    private function invokeElementHasAttribute(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_hasattr_invoke_cont');
        if (\count($args) < 2) {
            throw new \LogicException('Dom\\Element::hasAttribute() expects receiver and name');
        }
        $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $i1 = $context->getTypeFromString('int1');
        $slot = JitValueBox::alloc($context);
        if (null === $nameLit) {
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return JitValueBox::normalizeValuePtr($context, $slot);
        }
        $attr = DomUserScriptAttributeCacheLlvm::lookupLiteral($context, '', $nameLit);
        $objPtr = $context->getTypeFromString('__object__*');
        $isPresent = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $attr,
            $objPtr->constNull()
        );
        JitValueBox::writeBool($context, $slot, $isPresent);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /** DOMElement::hasAttributeNS() — user-script AOT live Attr cache (#32398, #33762). */
    private function invokeElementHasAttributeNS(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_hasattrns_invoke_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMElement::hasAttributeNS',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        // Prefer isNullConstant over compileTimeString (stale cts on null args; #33532).
        $nsLit = self::compileTimeNamespace($args[1]);
        $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        $present = null !== $nsLit && null !== $localLit
            && DomUserScriptAttributeCacheLlvm::hasPresentLiteral($nsLit, $localLit);

        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt($present ? 1 : 0, false));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /** DOMNode::remove() — user-script AOT ChildNode (#26752). */
    private function invokeNodeRemove(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_child_remove_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::remove() called without $this');
        }
        $given = VmClassMethod::jitUserArgCount($context, $args);
        if (0 !== $given) {
            $function = DomJitArgc::childNodeRemoveAceFunction($context, $args[0]);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                DomClassMethod::exactUserArgCountMessage($function, 0, $given)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_child_remove_ace_cont');

            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return DomNodeChildNodeMutationRuntime::invokeRemove($context, $args[0]);
    }

    /** DOMXPath::registerPhpFunctions() — user-script AOT (#27575). */
    private function invokeXpathRegisterPhpFunctions(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_register_php_functions_cont');
        if ([] === $args) {
            throw new \LogicException('DOMXPath::registerPhpFunctions() called without $this');
        }
        $argcDummy = DomJitArgc::rejectUnlessUserArgCountRange(
            $context,
            $args,
            'DOMXPath::registerPhpFunctions',
            0,
            1
        );
        if (null !== $argcDummy) {
            return $argcDummy;
        }
        if (JitDomXPathRegisterUserScript::shouldUse($context)) {
            $us = JitDomXPathRegisterUserScript::tryRegisterPhpFunctions($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }
        $extra = \array_slice($args, 1);

        return DomInstanceMethodRuntime::invoke(
            $context,
            \count($extra),
            'registerphpfunctions',
            $args[0],
            ...$extra
        );
    }

    /** DOMXPath::registerNamespace() — user-script AOT (#27575). */
    private function invokeXpathRegisterNamespace(Context $context, JITVariable ...$args): Value
    {

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_register_namespace_cont');
        if (\count($args) < 3) {
            throw new \LogicException('DOMXPath::registerNamespace() expects receiver, prefix, and URI');
        }
        // Z_PARAM_STR: strict null → TypeError before user-script fold (#30301, sibling #30041).
        if ($context->callerStrictTypes) {
            if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
                return self::emitNullStringTypeError(
                    $context,
                    'DOMXPath::registerNamespace(): Argument #1 ($prefix) must be of type string, null given'
                );
            }
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                return self::emitNullStringTypeError(
                    $context,
                    'DOMXPath::registerNamespace(): Argument #2 ($namespace) must be of type string, null given'
                );
            }
        }
        if (JitDomXPathRegisterUserScript::shouldUse($context)) {
            $us = JitDomXPathRegisterUserScript::tryRegisterNamespace($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }
        $extra = \array_slice($args, 1);

        return DomInstanceMethodRuntime::invoke(
            $context,
            \count($extra),
            'registernamespace',
            $args[0],
            ...$extra
        );
    }

    private static function compileTimeNamespace(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return '';
        }

        return JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
    }

    private static function emitNullStringTypeError(Context $context, string $message): Value
    {
        JitNativeString::ensureInsertBlock($context);
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

}
