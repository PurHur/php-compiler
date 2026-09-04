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
use PHPCompiler\JIT\Builtin\DomLoadRuntime;
use PHPCompiler\JIT\Builtin\DomLoadHTMLRuntime;
use PHPCompiler\JIT\Builtin\DomAttrIsIdRuntime;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\Builtin\VmClassMethod;

/**
 * dom surfaces for lib/JIT Call Dom* thin proxies + Dom*Runtime kernels (#36204).
 *
 * php-src: ext/dom/*.c — DOM* method thin-AOT kernels.
 * Registered from {@see Module::jitInit} so Call/Runtime files do not import ext/dom.
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
            'node.after' => $this->invokeNodeAfter($context, ...$args),
            'node.before' => $this->invokeNodeBefore($context, ...$args),
            'node.replaceWith' => $this->invokeNodeReplaceWith($context, ...$args),
            'node.remove' => $this->invokeNodeRemove($context, ...$args),
            'node.append' => $this->invokeNodeAppend($context, ...$args),
            'node.prepend' => $this->invokeNodePrepend($context, ...$args),
            'node.replaceChildren' => $this->invokeNodeReplaceChildren($context, ...$args),

            'xpath.registerPhpFunctions' => $this->invokeXpathRegisterPhpFunctions($context, ...$args),
            'xpath.registerNamespace' => $this->invokeXpathRegisterNamespace($context, ...$args),
            'attr.isId' => $this->invokeAttrIsId($context, ...$args),
            'document.construct' => $this->invokeDocumentConstruct($context, ...$args),
            'document.load' => $this->invokeDocumentLoad($context, ...$args),
            'document.loadHTML' => $this->invokeDocumentLoadHTML($context, ...$args),
            'element.getAttribute' => $this->invokeElementGetAttribute($context, ...$args),
            'element.getAttributeNS' => $this->invokeElementGetAttributeNS($context, ...$args),
            'element.removeAttribute' => $this->invokeElementRemoveAttribute($context, ...$args),
            'element.removeAttributeNS' => $this->invokeElementRemoveAttributeNS($context, ...$args),
            'element.setAttribute' => $this->invokeElementSetAttribute($context, ...$args),
            'element.setAttributeNS' => $this->invokeElementSetAttributeNS($context, ...$args),
            'node.appendChild' => $this->invokeNodeAppendChild($context, ...$args),
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

    /** DOMNode::append() — user-script AOT ParentNode (#18951, #19208). */
    private function invokeNodeAppend(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_append_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::append() called without $this');
        }

        return JitDomLiveMutationKernel::invokeAppend(
            $context,
            \count($args) - 1,
            $args[0],
            ...\array_slice($args, 1)
        );
    }

    /** DOMNode::prepend() — user-script AOT ParentNode (#18951). */
    private function invokeNodePrepend(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_prepend_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::prepend() called without $this');
        }

        return JitDomLiveMutationKernel::invokePrepend(
            $context,
            \count($args) - 1,
            $args[0],
            ...\array_slice($args, 1)
        );
    }

    /** DOMNode::replaceChildren() — user-script AOT ParentNode (#18951). */
    private function invokeNodeReplaceChildren(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replacechildren_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::replaceChildren() called without $this');
        }

        return JitDomLiveMutationKernel::invokeReplaceChildren(
            $context,
            \count($args) - 1,
            $args[0],
            ...\array_slice($args, 1)
        );
    }

    /** DOMNode::after() — user-script AOT ChildNode (#26752). */
    private function invokeNodeAfter(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_after_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::after() called without $this');
        }

        return JitDomChildNodeMutationKernel::invokeAfter(
            $context,
            \count($args) - 1,
            $args[0],
            ...\array_slice($args, 1)
        );
    }

    /** DOMNode::before() — user-script AOT ChildNode (#26752). */
    private function invokeNodeBefore(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_before_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::before() called without $this');
        }

        return JitDomChildNodeMutationKernel::invokeBefore(
            $context,
            \count($args) - 1,
            $args[0],
            ...\array_slice($args, 1)
        );
    }

    /** DOMNode::replaceWith() — user-script AOT ChildNode (#26752). */
    private function invokeNodeReplaceWith(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replacewith_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::replaceWith() called without $this');
        }

        return JitDomChildNodeMutationKernel::invokeReplaceWith(
            $context,
            \count($args) - 1,
            $args[0],
            ...\array_slice($args, 1)
        );
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

        return JitDomChildNodeMutationKernel::invokeRemove($context, $args[0]);
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


    /**
 * DOMAttr::isId() — user-script AOT (#29884).
 *
 * Always runtime via NestedJIT — compile-time idBearing stamps mis-fold when CFG
 * lowering order differs from source order (maintainer_gap #29884).
 */

    private function invokeAttrIsId(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_attr_isid_invoke_cont');
        JitDomAttributeNodeNS::ensureClassicAttrMethods($context);
        if ([] === $args) {
            throw new \LogicException('DOMAttr::isId() expects receiver');
        }

        if (JitDomAttrRename::lastAttrIsOrphan()) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $key = JitDomAttrRename::lastFetchedKey();
        if (null !== $key) {
            $active = strtolower($context->activeFunction);
            $inUserDeclaredFunction = '' !== $active
                && !str_starts_with($active, '__')
                && in_array($active, $context->userFunctionNames(), true);
            // loadXML DTD ATTLIST ID / xml:id / setIdAttribute* stamp compile-time flags (#34821).
            // Module-wide idBearing stamps pollute user-function CFG paths (#23514 importNode).
            if (!$inUserDeclaredFunction
                && DomUserScriptAttributeCacheLlvm::isIdBearingLiteral($key[0], $key[1])
            ) {
                return $context->getTypeFromString('int1')->constInt(1, false);
            }
            if ($inUserDeclaredFunction && '' === $key[0] && 'id' === $key[1]) {
                // createElement id= toggles via module global — runtime stores from setIdAttribute (#29884).
                return DomUserScriptAttributeCacheLlvm::loadIdBearingGlobal($context);
            }

            return DomAttrIsIdRuntime::invoke($context, $args[0]);
        }

        return DomAttrIsIdRuntime::invoke($context, $args[0]);
    }


    /**
 * DOMDocument::__construct — seed thin-AOT DOMNode::$nodeType (#33607).
 *
 * php-src: ext/dom/document.c / node.c — XML_DOCUMENT_NODE.
 * Must be listed in JIT::isVoidJitConstructCall so markObjectConstructed runs.
 */

    private function invokeDocumentConstruct(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DOMDocument::__construct() called without $this');
        }
        $obj = self::objectPtr($context, $args[0]);
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, VmDom::PROP_NODE_TYPE)) {
            $objectType->defineProperty($classId, VmDom::PROP_NODE_TYPE, JITVariable::TYPE_NATIVE_LONG);
        }
        JitDomCreateElement::storeNodeType(
            $context,
            $obj,
            'DOMDocument',
            DomConstants::XML_DOCUMENT_NODE
        );

        // Empty document: documentElement is null (php-src ext/dom/document.c; #32736).
        if (!$objectType->hasProperty($classId, VmDom::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($classId, VmDom::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
        $nullEl = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('__object__*')->constNull()
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', VmDom::PROP_DOCUMENT_ELEMENT),
            $nullEl,
            JITVariable::TYPE_OBJECT
        );

        // Seed libxml option bools so reads work and writes stick (#34908).
        // php-src DOMDocument::__construct defaults — ext/dom/php_dom.c / document.c.
        self::seedOptionBool($context, $obj, VmDom::PROP_FORMAT_OUTPUT, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_VALIDATE_ON_PARSE, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_RESOLVE_EXTERNALS, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_SUBSTITUTE_ENTITIES, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_PRESERVE_WHITE_SPACE, true);
        self::seedOptionBool($context, $obj, VmDom::PROP_RECOVER, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_STRICT_ERROR_CHECKING, true);
        // xmlVersion / xmlStandalone (+ Level-3 aliases) — same MetaProps leftover (#34916).
        self::seedOptionBool($context, $obj, VmDom::PROP_XML_STANDALONE, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_STANDALONE, false);
        self::seedXmlVersion($context, $obj, '1.0');
        // encoding null — writable slot; xmlEncoding/actualEncoding alias via MetaProps (#34919).
        self::seedEncodingNull($context, $obj);
        // documentURI null — writable; baseURI read-only alias via MetaProps (#34925).
        self::seedDocumentUriNull($context, $obj);
        // DOMNode identity on Document (php-src ext/dom/node.c; #34992 leftover of #34899).
        self::seedNodeName($context, $obj, '#document');
        self::seedPrefixEmpty($context, $obj);
        self::seedNullValueProp($context, $obj, VmDom::PROP_NAMESPACE_URI);
        self::seedNullValueProp($context, $obj, VmDom::PROP_LOCAL_NAME);
        self::seedNullValueProp($context, $obj, VmDom::PROP_ATTRIBUTES);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function seedXmlVersion(Context $context, Value $obj, string $version): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        $str = $context->builder->load($context->constantStringFromString($version));
        foreach ([VmDom::PROP_XML_VERSION, VmDom::PROP_VERSION] as $prop) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, JITVariable::TYPE_STRING);
            }
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            $propVar = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $owned
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, 'DOMDocument', $prop),
                $propVar,
                JITVariable::TYPE_STRING
            );
        }
    }

    /** php-src DOMDocument::$encoding default null (ext/dom/php_dom.c; #34919). */
    private static function seedEncodingNull(Context $context, Value $obj): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, VmDom::PROP_ENCODING)) {
            $objectType->defineProperty($classId, VmDom::PROP_ENCODING, JITVariable::TYPE_VALUE);
        }
        $box = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $box)
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $box
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', VmDom::PROP_ENCODING),
            $propVar,
            JITVariable::TYPE_VALUE
        );
    }

    /** php-src DOMDocument::$documentURI default null (ext/dom/document.c; #34925). */
    private static function seedDocumentUriNull(Context $context, Value $obj): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, VmDom::PROP_DOCUMENT_URI)) {
            $objectType->defineProperty($classId, VmDom::PROP_DOCUMENT_URI, JITVariable::TYPE_VALUE);
        }
        $box = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $box)
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $box
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', VmDom::PROP_DOCUMENT_URI),
            $propVar,
            JITVariable::TYPE_VALUE
        );
    }

    private static function seedOptionBool(Context $context, Value $obj, string $prop, bool $value): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, $prop)) {
            $objectType->defineProperty($classId, $prop, JITVariable::TYPE_VALUE);
        }
        $box = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        JitValueBox::writeBool(
            $context,
            $box,
            $context->builder->zext($i1->constInt($value ? 1 : 0, false), $i32)
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $box
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', $prop),
            $propVar,
            JITVariable::TYPE_VALUE
        );
    }

    /** php-src DOMNode::$nodeName for XML_DOCUMENT_NODE — "#document" (#34992). */
    private static function seedNodeName(Context $context, Value $obj, string $name): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, VmDom::PROP_NODE_NAME)) {
            $objectType->defineProperty($classId, VmDom::PROP_NODE_NAME, JITVariable::TYPE_STRING);
        }
        $str = $context->builder->load($context->constantStringFromString($name));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $owned
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', VmDom::PROP_NODE_NAME),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    /** php-src DOMNode::$prefix for documents — empty string (#34992). */
    private static function seedPrefixEmpty(Context $context, Value $obj): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, VmDom::PROP_PREFIX)) {
            $objectType->defineProperty($classId, VmDom::PROP_PREFIX, JITVariable::TYPE_STRING);
        }
        $str = $context->builder->load($context->constantStringFromString(''));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $owned
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', VmDom::PROP_PREFIX),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    /**
     * Seed a nullable DOMNode VALUE prop to null (namespaceURI / localName / attributes
     * on Document — #34992).
     */
    private static function seedNullValueProp(Context $context, Value $obj, string $prop): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, $prop)) {
            $objectType->defineProperty($classId, $prop, JITVariable::TYPE_VALUE);
        }
        $box = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $box)
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $box
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', $prop),
            $propVar,
            JITVariable::TYPE_VALUE
        );
    }

    private static function objectPtr(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException(
            'DOMDocument::__construct() expects an object, got '
            .JITVariable::getStringType($receiver->type)
        );
    }


    /** DOMDocument::load() — user-script AOT (#18897). */

    private function invokeDocumentLoad(Context $context, JITVariable ...$args): Value
    {
        if (JitDomDocumentMethodKernel::shouldUse($context) && isset($args[1])) {
            $path = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $path && '' !== trim($path)) {
                $xml = @\file_get_contents($path);
                if (false !== $xml && '' !== trim($xml)) {
                    JitDomLoadXMLUserScript::rememberCompileTimeXml($xml);
                }
            }
        }

        if (!JitDomDocumentMethodKernel::shouldUse($context)) {
            DomLoadRuntime::ensureLinked($context);
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::branchToFreshContinue($context, 'dom_load_invoke');
        }

        $result = JitDomLoad::invoke($context, ...$args);

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $mainCont = BasicBlockHelper::append($context, 'main_cont_after_dom_load');
            $context->builder->branch($mainCont);
            $context->builder->positionAtEnd($mainCont);
        }

        return $result;
    }


    /** DOMDocument::loadHTML() — user-script AOT (#17954). */

    private function invokeDocumentLoadHTML(Context $context, JITVariable ...$args): Value
    {
        $source = $args[1] ?? null;
        $isNullOrEmpty = null !== $source && (
            JITVariable::TYPE_NULL === $source->type
            || $source->isNullConstant
            || '' === (JitStringBuiltinArg::compileTimeLiteral($source) ?? $source->compileTimeString ?? null)
        );

        if (JitDomDocumentMethodKernel::shouldUse($context) && isset($args[1]) && !$isNullOrEmpty) {
            JitDomLoadHTMLUserScript::rememberCompileTimeOptions($context, $args[2] ?? null);
            $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $lit) {
                $parsed = DomParseSimpleHtmlJitHelper::parseArgv($lit);
                if (null !== $parsed) {
                    JitDomLoadHTMLUserScript::rememberCompileTimeParsed($parsed);
                }
            }
        }

        // Skip ABI link / fresh-continue for compile-time null/empty — ValueError IR only (#22680).
        if (!$isNullOrEmpty && !JitDomDocumentMethodKernel::shouldUse($context)) {
            DomLoadHTMLRuntime::ensureLinked($context);
        }

        if (!$isNullOrEmpty && JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::branchToFreshContinue($context, 'dom_lh_invoke');
        }

        $result = JitDomLoadHTML::invoke($context, ...$args);

        // Catchable ValueError leaves the insert block terminated (branch to try dispatch).
        // Do not stitch a reachable main_cont — that would run post-call try-body code (#22680).
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $insert = BasicBlockHelper::tryGetInsertBlock($context);
            if (null !== $insert && null === $insert->getTerminator()) {
                $mainCont = BasicBlockHelper::append($context, 'main_cont_after_dom_lh');
                $context->builder->branch($mainCont);
                $context->builder->positionAtEnd($mainCont);
            }
        }

        return $result;
    }


    /**
 * DOMElement::getAttribute() — user-script AOT (#19212, live Attr #19281, #27108, #34863).
 *
 * Prefer the receiver's compile-time open-tag stamp, then the element's own
 * attributes NamedNodeMap pins (php-src xmlGetProp). Never fall back to
 * process-global lastFetchedAttributes / name→value Attr cache — those collapse
 * sibling id= values after lastChild or getElementById (#34863 / re-#34050).
 */

    private function invokeElementGetAttribute(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattr_invoke_cont');
        $nameLit = null;
        if (isset($args[1])) {
            $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        }

        // Per-element open-tag stamp from firstChild/nextSibling only when still on
        // this Variable (#34050). Do not use lastFetchedAttributes — ARG_SEND /
        // getElementById receivers would inherit the last sibling's attrs (#34863).
        if (null !== $nameLit && isset($args[0])) {
            $attrs = $args[0]->compileTimeDomAttributes;
            if (null !== $attrs && [] !== $attrs) {
                $val = $attrs[$nameLit] ?? null;
                if (null === $val || '' === $val) {
                    $pos = strpos($nameLit, ':');
                    if (false !== $pos) {
                        $val = $attrs[substr($nameLit, $pos + 1)] ?? null;
                    }
                }
                if (null !== $val && '' !== $val) {
                    return self::boxConstantString($context, $val);
                }
            }
            // replaceChild clears the attrs bag on the return; read CreateElementAttrs (#35386).
            $id = $args[0]->compileTimeDomElementId ?? null;
            if (null !== $id) {
                $fromSide = JitDomCreateElementAttrs::get($id);
                if ([] !== $fromSide) {
                    $val = $fromSide[$nameLit] ?? null;
                    if (null === $val || '' === $val) {
                        $pos = strpos($nameLit, ':');
                        if (false !== $pos) {
                            $val = $fromSide[substr($nameLit, $pos + 1)] ?? null;
                        }
                    }
                    if (null !== $val && '' !== $val) {
                        return self::boxConstantString($context, $val);
                    }
                }
            }
        }

        // Per-element NamedNodeMap pins — correct after importNode / lastChild /
        // getElementById and for Attr::$value writes on attached attributes (#34863 / #19281).
        // Must run before the process-global Attr cache: a second loadHTML on another
        // document overwrites cache keys so importNode getAttribute('id') read 'other'
        // instead of the imported node's pinned id (#29487 / re-#19212).
        if (isset($args[0], $args[1])) {
            return JitDomNamedNodeMap::invokeElementGetAttribute($context, $args[0], $args[1]);
        }

        // User-script cache from createFromString / getAttributeNode — NamedNodeMap may
        // lack pins until appendChild/setAttribute; read live Attr::$value (#21083).
        if (null !== $nameLit && isset($args[0]) && self::cacheHasPresentLiteralName($nameLit)) {
            return JitDomAttributeNodeNS::invokeGetAttributeLive($context, ...$args);
        }

        // Otherwise fall back to importNode/getElementById HTML-id stub (#19212).
        return JitDomImportNode::invokeGetAttribute($context, ...$args);
    }

    private static function cacheHasPresentLiteralName(string $nameLit): bool
    {
        if (DomUserScriptAttributeCacheLlvm::hasPresentLiteral('', $nameLit)) {
            return true;
        }
        $pos = strpos($nameLit, ':');

        return false !== $pos
            && DomUserScriptAttributeCacheLlvm::hasPresentLiteral('', substr($nameLit, $pos + 1));
    }


    /**
 * Dom\Element::getAttributeNS() — thin user-script AOT live Attr cache (#27108).
 * Null namespace prefers isNullConstant over stale compileTimeString (#33532).
 */

    private function invokeElementGetAttributeNS(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattrns_invoke_cont');
        // Legacy DOMElement + living Dom\Element share this Call (#31011).
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMElement::getAttributeNS',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        // Prefer isNullConstant over compileTimeString — null args can carry a stale
        // string stamp (observed cts='k' with nullConst=1), which keyed the lookup as
        // namespace "k" instead of "" (#33532 / peer SetAttributeNS #33528).
        $nsLit = self::compileTimeNamespace($args[1]);
        $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $nsLit || null === $localLit
            || !DomUserScriptAttributeCacheLlvm::hasPresentLiteral($nsLit, $localLit)
        ) {
            return self::boxConstantString($context, '');
        }

        return self::boxConstantString(
            $context,
            DomUserScriptAttributeCacheLlvm::literalValue($nsLit, $localLit) ?? ''
        );
    }


    /** DOMElement::removeAttribute() — user-script AOT (#19870). */

    private function invokeElementRemoveAttribute(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_removeattr_invoke_cont');
        $id = null;
        $hadLoadXml = null !== JitDomLoadXMLUserScript::lastCompileTimeXml();
        $didRefreshRootXml = false;
        // Keep createElement attr bag + loadXML C14N fold in sync (#32981 / #34257).
        if (\count($args) >= 2) {
            $name = $args[1]->compileTimeString;
            if (null !== $name && 'xmlns' !== $name) {
                $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
                if (null !== $id) {
                    JitDomCreateElementAttrs::remove($id, $name);
                }
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                if (isset($attrs[$name])) {
                    unset($attrs[$name]);
                    $args[0]->compileTimeDomAttributes = $attrs;
                }
                $path = $args[0]->compileTimeDomNodePath ?? null;
                $nested = null !== $path && '' !== $path
                    && substr_count(trim($path, '/'), '/') >= 1;
                if ($nested) {
                    JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();
                } else {
                    JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeRemove($name);
                    $didRefreshRootXml = $hadLoadXml;
                }
            }
        }

        $result = JitDomAttributeNodeNS::invokeRemoveAttribute($context, ...$args);

        // Drop attr from saveXML open-tag suffix (#33509 / loadXML #34257).
        if (\count($args) >= 2) {
            $name = $args[1]->compileTimeString;
            if (null !== $name && 'xmlns' !== $name) {
                if ($didRefreshRootXml) {
                    JitDomLoadXMLUserScript::syncElementXmlnsAttrFromCompileTimeXml($context, $args[0]);
                } else {
                    $attrs = $args[0]->compileTimeDomAttributes;
                    if (null === $attrs && null !== $id) {
                        $attrs = JitDomCreateElementAttrs::get($id);
                    }
                    if (null !== $attrs) {
                        JitDomAttributeNodeNS::syncSaveXmlAttrSuffix($context, $args[0], $attrs);
                    }
                }
            }
        }

        return $result;
    }


    /**
 * DOMElement::removeAttributeNS() — user-script AOT (#32398, php-src returns null).
 *
 * Drops NS attrs from the saveXML open-tag bag (#33526 / peer #33509).
 * loadXML roots: also refresh compile-time XML + PROP_USER_SCRIPT_XMLNS_ATTR (#34257).
 */

    private function invokeElementRemoveAttributeNS(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_removeattrns_invoke_cont');
        $id = null;
        $removed = [];
        $local = null;
        $hadLoadXml = null !== JitDomLoadXMLUserScript::lastCompileTimeXml();
        $didRefreshRootXml = false;
        if (\count($args) >= 3) {
            $local = $args[2]->compileTimeString;
            $nsKnown = $args[1]->isNullConstant || null !== $args[1]->compileTimeString;
            if (null !== $local && $nsKnown && 'xmlns' !== $local) {
                $ns = $args[1]->isNullConstant ? null : $args[1]->compileTimeString;
                $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                if (null !== $id) {
                    foreach (JitDomCreateElementAttrs::get($id) as $name => $value) {
                        if (!isset($attrs[$name])) {
                            $attrs[$name] = $value;
                        }
                    }
                }
                $removed = self::removeLocalFromBag($attrs, $local);
                if (null !== $id) {
                    foreach ($removed as $name) {
                        JitDomCreateElementAttrs::remove($id, $name);
                    }
                }
                $args[0]->compileTimeDomAttributes = $attrs;

                $path = $args[0]->compileTimeDomNodePath ?? null;
                $nested = null !== $path && '' !== $path
                    && substr_count(trim($path, '/'), '/') >= 1;
                if ($nested) {
                    JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();
                } elseif ($hadLoadXml) {
                    // Always mutate host XML — bag is empty for loadXML-seeded attrs (#34257).
                    JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeRemoveNS($ns, $local);
                    $didRefreshRootXml = true;
                } else {
                    foreach ($removed as $name) {
                        JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeRemove($name);
                    }
                }
            }
        }

        $result = JitDomAttributeNodeNS::invokeRemoveAttributeNS($context, ...$args);

        if (null !== $local && 'xmlns' !== $local) {
            $nsKnown = isset($args[1]) && ($args[1]->isNullConstant || null !== $args[1]->compileTimeString);
            if ($nsKnown) {
                if ($didRefreshRootXml) {
                    JitDomLoadXMLUserScript::syncElementXmlnsAttrFromCompileTimeXml($context, $args[0]);
                } else {
                    $attrs = $args[0]->compileTimeDomAttributes;
                    if (null === $attrs && null !== $id) {
                        $attrs = JitDomCreateElementAttrs::get($id);
                    }
                    if (null !== $attrs) {
                        JitDomAttributeNodeNS::syncSaveXmlAttrSuffix($context, $args[0], $attrs);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $attrs
     *
     * @return list<string> removed open-tag keys (qName only — keep xmlns:prefix like Zend)
     */
    private static function removeLocalFromBag(array &$attrs, string $localName): array
    {
        $removed = [];
        foreach (array_keys($attrs) as $name) {
            if (str_starts_with($name, 'xmlns')) {
                continue;
            }
            $local = str_contains($name, ':') ? substr($name, (int) strrpos($name, ':') + 1) : $name;
            if ($local !== $localName) {
                continue;
            }
            unset($attrs[$name]);
            $removed[] = $name;
        }

        return $removed;
    }


    /** DOMElement::setAttribute() — user-script AOT live Attr (#19281). */

    private function invokeElementSetAttribute(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattr_invoke_cont');
        $id = null;
        // Side-table: assign/box can drop createElement stamps on the local (#32973).
        if (\count($args) >= 3) {
            $name = $args[1]->compileTimeString;
            $value = $args[2]->compileTimeString;
            $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
            if (null !== $name && null !== $value && 'xmlns' !== $name) {
                // Side-table only before invoke — merging compileTimeDomAttributes here runs
                // before invokeSetAttribute reads prior id for htmlSetProp / isId (#23514).
                if (null !== $id) {
                    JitDomCreateElementAttrs::set($id, $name, $value);
                }
            }
            // loadXML documentElement C14N fold (#32981). Nested paths invalidate.
            if (null !== $name && null !== $value && 'xmlns' !== $name) {
                $path = $args[0]->compileTimeDomNodePath ?? null;
                $nested = null !== $path && '' !== $path
                    && substr_count(trim($path, '/'), '/') >= 1;
                if ($nested) {
                    JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();
                } else {
                    JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeSet($name, $value);
                }
            }
        }

        $result = JitDomAttributeNodeNS::invokeSetAttribute($context, ...$args);

        // saveXML / INNER_XML rebuild read PROP_USER_SCRIPT_XMLNS_ATTR (#33509 / peer #33362).
        if (\count($args) >= 3) {
            $name = $args[1]->compileTimeString;
            $value = $args[2]->compileTimeString;
            if (null !== $name && null !== $value && 'xmlns' !== $name) {
                // Merge after invoke so compileTimePriorIdLiteral sees removeAttribute clears (#23514).
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                if ([] === $attrs && null !== $id) {
                    $attrs = JitDomCreateElementAttrs::get($id);
                }
                $attrs[$name] = $value;
                $args[0]->compileTimeDomAttributes = $attrs;
                if (null === $args[0]->compileTimeDomElementId && null !== $id) {
                    $args[0]->compileTimeDomElementId = $id;
                }
                if (null !== $attrs) {
                    JitDomAttributeNodeNS::syncSaveXmlAttrSuffix($context, $args[0], $attrs);
                }
            }
        }

        return $result;
    }


    /**
 * DOMElement::setAttributeNS() — user-script AOT (#32398, php-src xmlSetNsProp).
 *
 * Syncs PROP_USER_SCRIPT_XMLNS_ATTR like {@see DomElementSetAttribute} (#33526 / peer #33509).
 */

    private function invokeElementSetAttributeNS(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrns_invoke_cont');
        $id = null;
        if (\count($args) >= 4) {
            $qname = $args[2]->compileTimeString;
            $value = $args[3]->compileTimeString;
            $nsKnown = $args[1]->isNullConstant || null !== $args[1]->compileTimeString;
            if (null !== $qname && null !== $value && $nsKnown
                && 'xmlns' !== $qname && !str_starts_with($qname, 'xmlns:')) {
                $ns = $args[1]->isNullConstant ? null : $args[1]->compileTimeString;
                $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
                $bagUpdates = self::openTagAttrUpdates($ns, $qname, $value);
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                if ([] === $attrs && null !== $id) {
                    $attrs = JitDomCreateElementAttrs::get($id);
                }
                // Rebuild so xmlns:prefix stays before the prefixed Attr (Zend serialize order).
                foreach ($bagUpdates as $name => $val) {
                    unset($attrs[$name]);
                }
                $attrs = $bagUpdates + $attrs;
                if (null !== $id) {
                    foreach ($bagUpdates as $name => $val) {
                        // Numeric-looking qnames become int keys in PHP arrays; cast (#35234).
                        JitDomCreateElementAttrs::set($id, (string) $name, (string) $val);
                    }
                    if (null === $args[0]->compileTimeDomElementId) {
                        $args[0]->compileTimeDomElementId = $id;
                    }
                }
                $args[0]->compileTimeDomAttributes = $attrs;

                $path = $args[0]->compileTimeDomNodePath ?? null;
                $nested = null !== $path && '' !== $path
                    && substr_count(trim($path, '/'), '/') >= 1;
                if ($nested) {
                    JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();
                } else {
                    foreach ($bagUpdates as $name => $val) {
                        JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeSet($name, $val);
                    }
                }
            }
        }

        $result = JitDomAttributeNodeNS::invokeSetAttributeNS($context, ...$args);

        if (\count($args) >= 4) {
            $qname = $args[2]->compileTimeString;
            $value = $args[3]->compileTimeString;
            $nsKnown = $args[1]->isNullConstant || null !== $args[1]->compileTimeString;
            if (null !== $qname && null !== $value && $nsKnown
                && 'xmlns' !== $qname && !str_starts_with($qname, 'xmlns:')) {
                $attrs = $args[0]->compileTimeDomAttributes;
                if (null === $attrs && null !== $id) {
                    $attrs = JitDomCreateElementAttrs::get($id);
                }
                if (null !== $attrs) {
                    JitDomAttributeNodeNS::syncSaveXmlAttrSuffix($context, $args[0], $attrs);
                }
            }
        }

        return $result;
    }

    /**
     * Open-tag keys for saveXML: qName=value plus xmlns:prefix when prefixed (php-src xmlSetNsProp).
     *
     * @return array<string, string>
     */
    private static function openTagAttrUpdates(?string $namespace, string $qname, string $value): array
    {
        // php-src emits xmlns:prefix before the prefixed Attr in serialization.
        $updates = [];
        $colon = strpos($qname, ':');
        if (false !== $colon && null !== $namespace && '' !== $namespace) {
            $prefix = substr($qname, 0, $colon);
            if ('' !== $prefix && 'xmlns' !== $prefix) {
                $updates['xmlns:'.$prefix] = $namespace;
            }
        }
        $updates[$qname] = $value;

        return $updates;
    }


    /**
 * DOMNode::appendChild() — user-script AOT (#18478, #18927, #27044, #27480).
 *
 * Prefer ParentNode::append lowering (live mutation + childNodes length). The
 * historic JitDomAppendChild stub only wrote parentNode; RuntimeIndirect on
 * Element class_ids aborts when Document/Node are the only candidates (#19208).
 *
 * Capture the child {@see __object__*} before live-slot sync and re-box it for
 * the return value (peer insertBefore). Re-reading `$args[1]` after sync via
 * {@see JitValueBox::valuePtrFromVariable} observes a null box — appendChild
 * then returns NULL and `$a->parentNode` after replaceChild segfaults (#27480).
 */

    private function invokeNodeAppendChild(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ac_invoke_cont');
        if (
            isset($args[1])
            && JitDomRequireDomNodeArg::guardOrAbort($context, $args[1], 'DOMNode::appendChild', 1, 'node')
        ) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }
        if (
            JitDomDocumentMethodKernel::shouldUse($context)
            && \count($args) >= 2
        ) {
            // Pin object identity before ParentNode::append mutates slots (#27480).
            $childObj = self::loadChildObject($context, $args[1]);
            $append = new \PHPCompiler\JIT\Call\DomNodeAppend();
            $append->call($context, ...$args);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ac_ret_cont');

            return self::boxObjectResult($context, $childObj);
        }

        return JitDomAppendChild::invoke($context, ...$args);
    }

    private static function loadChildObject(Context $context, JITVariable $child): Value
    {
        if (JITVariable::TYPE_OBJECT === $child->type) {
            return $context->helper->loadValue($child);
        }
        if (JITVariable::TYPE_VALUE === $child->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $child)
            );
        }

        throw new \LogicException('DOMNode::appendChild() child must be object or value box');
    }

    private static function boxObjectResult(Context $context, Value $object): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $object
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }


    private static function boxConstantString(Context $context, string $lit): Value
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
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


    public function ensureDocumentMethodBridge(Context $context, string $bridgeId): void
    {
        match ($bridgeId) {
            'AdoptNode' => JitDomDocumentMethodKernel::ensureAdoptNodeBridge($context),
            'AttrIsId' => JitDomDocumentMethodKernel::ensureAttrIsIdBridge($context),
            'C14N' => JitDomDocumentMethodKernel::ensureC14NBridge($context),
            'C14NFile' => JitDomDocumentMethodKernel::ensureC14NFileBridge($context),
            'ChildElementCount' => JitDomDocumentMethodKernel::ensureChildElementCountBridge($context),
            'CreateAttribute' => JitDomDocumentMethodKernel::ensureCreateAttributeBridge($context),
            'CreateAttributeNS' => JitDomDocumentMethodKernel::ensureCreateAttributeNSBridge($context),
            'CreateElement' => JitDomDocumentMethodKernel::ensureCreateElementBridge($context),
            'CreateElementNS' => JitDomDocumentMethodKernel::ensureCreateElementNSBridge($context),
            'DocumentRegisterNodeClass' => JitDomDocumentMethodKernel::ensureDocumentRegisterNodeClassBridge($context),
            'DocumentRelaxNGValidate' => JitDomDocumentMethodKernel::ensureDocumentRelaxNGValidateBridge($context),
            'DocumentRelaxNGValidateSource' => JitDomDocumentMethodKernel::ensureDocumentRelaxNGValidateSourceBridge($context),
            'DocumentSchemaValidate' => JitDomDocumentMethodKernel::ensureDocumentSchemaValidateBridge($context),
            'DocumentSchemaValidateSource' => JitDomDocumentMethodKernel::ensureDocumentSchemaValidateSourceBridge($context),
            'DocumentValidate' => JitDomDocumentMethodKernel::ensureDocumentValidateBridge($context),
            'DocumentXInclude' => JitDomDocumentMethodKernel::ensureDocumentXIncludeBridge($context),
            'ElementNodeValueWrite' => JitDomDocumentMethodKernel::ensureElementNodeValueWriteBridge($context),
            'ElementTextContent' => JitDomDocumentMethodKernel::ensureElementTextContentBridge($context),
            'ElementTextContentWrite' => JitDomDocumentMethodKernel::ensureElementTextContentWriteBridge($context),
            'FirstChild' => JitDomDocumentMethodKernel::ensureFirstChildBridge($context),
            'FirstElementChild' => JitDomDocumentMethodKernel::ensureFirstElementChildBridge($context),
            'GetAttribute' => JitDomDocumentMethodKernel::ensureGetAttributeBridge($context),
            'GetAttributeNodeNS' => JitDomDocumentMethodKernel::ensureGetAttributeNodeNSBridge($context),
            'GetElementById' => JitDomDocumentMethodKernel::ensureGetElementByIdBridge($context),
            'GetElementsByTagName' => JitDomDocumentMethodKernel::ensureGetElementsByTagNameBridge($context),
            'HtmlDocumentCreateFromFile' => JitDomDocumentMethodKernel::ensureHtmlDocumentCreateFromFileBridge($context),
            'HtmlDocumentCreateFromString' => JitDomDocumentMethodKernel::ensureHtmlDocumentCreateFromStringBridge($context),
            'ImportNode' => JitDomDocumentMethodKernel::ensureImportNodeBridge($context),
            'InsertAdjacentElement' => JitDomDocumentMethodKernel::ensureInsertAdjacentElementBridge($context),
            'InsertAdjacentText' => JitDomDocumentMethodKernel::ensureInsertAdjacentTextBridge($context),
            'InsertBefore' => JitDomDocumentMethodKernel::ensureInsertBeforeBridge($context),
            'IsConnected' => JitDomDocumentMethodKernel::ensureIsConnectedBridge($context),
            'LastChild' => JitDomDocumentMethodKernel::ensureLastChildBridge($context),
            'LastElementChild' => JitDomDocumentMethodKernel::ensureLastElementChildBridge($context),
            'Load' => JitDomDocumentMethodKernel::ensureLoadBridge($context),
            'LoadHTML' => JitDomDocumentMethodKernel::ensureLoadHTMLBridge($context),
            'LoadHTMLFile' => JitDomDocumentMethodKernel::ensureLoadHTMLFileBridge($context),
            'LoadXML' => JitDomDocumentMethodKernel::ensureLoadXMLBridge($context),
            'NextElementSibling' => JitDomDocumentMethodKernel::ensureNextElementSiblingBridge($context),
            'NextSibling' => JitDomDocumentMethodKernel::ensureNextSiblingBridge($context),
            'NodeListItem' => JitDomDocumentMethodKernel::ensureNodeListItemBridge($context),
            'Normalize' => JitDomDocumentMethodKernel::ensureNormalizeBridge($context),
            'NormalizeDocument' => JitDomDocumentMethodKernel::ensureNormalizeDocumentBridge($context),
            'ParentNode' => JitDomDocumentMethodKernel::ensureParentNodeBridge($context),
            'PreviousElementSibling' => JitDomDocumentMethodKernel::ensurePreviousElementSiblingBridge($context),
            'PreviousSibling' => JitDomDocumentMethodKernel::ensurePreviousSiblingBridge($context),
            'RemoveAttributeNode' => JitDomDocumentMethodKernel::ensureRemoveAttributeNodeBridge($context),
            'RemoveChild' => JitDomDocumentMethodKernel::ensureRemoveChildBridge($context),
            'ReplaceChild' => JitDomDocumentMethodKernel::ensureReplaceChildBridge($context),
            'SaveHTML' => JitDomDocumentMethodKernel::ensureSaveHTMLBridge($context),
            'SaveHTMLFile' => JitDomDocumentMethodKernel::ensureSaveHTMLFileBridge($context),
            'SaveXML' => JitDomDocumentMethodKernel::ensureSaveXMLBridge($context),
            'SetAttributeNode' => JitDomDocumentMethodKernel::ensureSetAttributeNodeBridge($context),
            'SetAttributeNodeNS' => JitDomDocumentMethodKernel::ensureSetAttributeNodeNSBridge($context),
            'SetIdAttributeFalse' => JitDomDocumentMethodKernel::ensureSetIdAttributeFalseBridge($context),
            'SetIdAttributeNodeFalse' => JitDomDocumentMethodKernel::ensureSetIdAttributeNodeFalseBridge($context),
            'SetIdAttributeNodeTrue' => JitDomDocumentMethodKernel::ensureSetIdAttributeNodeTrueBridge($context),
            'SetIdAttributeNsFalse' => JitDomDocumentMethodKernel::ensureSetIdAttributeNsFalseBridge($context),
            'SetIdAttributeNsTrue' => JitDomDocumentMethodKernel::ensureSetIdAttributeNsTrueBridge($context),
            'SetIdAttributeTrue' => JitDomDocumentMethodKernel::ensureSetIdAttributeTrueBridge($context),
            'SyncElementIdMap' => JitDomDocumentMethodKernel::ensureSyncElementIdMapBridge($context),
            'XPathEvaluateBool' => JitDomDocumentMethodKernel::ensureXPathEvaluateBoolBridge($context),
            'XPathEvaluateDouble' => JitDomDocumentMethodKernel::ensureXPathEvaluateDoubleBridge($context),
            'XPathEvaluateString' => JitDomDocumentMethodKernel::ensureXPathEvaluateStringBridge($context),
            'XPathQuery' => JitDomDocumentMethodKernel::ensureXPathQueryBridge($context),
            'XmlDocumentCreateFromFile' => JitDomDocumentMethodKernel::ensureXmlDocumentCreateFromFileBridge($context),
            'XmlDocumentCreateFromString' => JitDomDocumentMethodKernel::ensureXmlDocumentCreateFromStringBridge($context),
            'Contains' => JitDomDocumentMethodKernel::ensureContainsBridge($context),
            'CompareDocumentPosition' => JitDomDocumentMethodKernel::ensureCompareDocumentPositionBridge($context),
            'GetRootNode' => JitDomDocumentMethodKernel::ensureGetRootNodeBridge($context),
            'IsEqualNode' => JitDomDocumentMethodKernel::ensureIsEqualNodeBridge($context),
            'ToggleAttributeOmit' => JitDomDocumentMethodKernel::ensureToggleAttributeOmitBridge($context),
            'ToggleAttributeForceTrue' => JitDomDocumentMethodKernel::ensureToggleAttributeForceTrueBridge($context),
            'ToggleAttributeForceFalse' => JitDomDocumentMethodKernel::ensureToggleAttributeForceFalseBridge($context),
            default => throw new \InvalidArgumentException(
                'Unknown DOM document method bridge: '.$bridgeId
            ),
        };
    }

    public function ensureInstanceMethodBridge(Context $context, int $extraArgCount): void
    {
        JitDomInstanceMethodKernel::ensureBridge($context, $extraArgCount);
    }

    public function ensureStandaloneAotInit(Context $context): void
    {
        JitDomStandaloneAotInitKernel::ensureLinked($context);
    }

    public function requireDomNodeArgGuardOrAbort(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName,
        string $expectedClass = 'DOMNode'
    ): bool {
        return JitDomRequireDomNodeArg::guardOrAbort(
            $context,
            $arg,
            $function,
            $userArgIndex,
            $paramName,
            $expectedClass
        );
    }

    public function emitToggleAttributeInt1(
        Context $context,
        Value $element,
        string $nameLit,
        string $mode
    ): Value {
        return JitDomAttributeNodeNS::emitToggleAttributeInt1(
            $context,
            $element,
            $nameLit,
            $mode
        );
    }

}
