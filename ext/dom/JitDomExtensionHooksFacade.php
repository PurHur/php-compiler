<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\DomExtensionHooks;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

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
            default => throw new \LogicException(
                'Unknown DOM Call kernel id: '.$callId.' (#36204)'
            ),
        };
    }
}
