<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\ThrowableManifest;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\VM\Variable;

/** Register PHP 8.4 Dom\ living-standard classes (php-src ext/dom/php_dom.stub.php; #6506). */
final class DomLivingBuiltinClasses
{
    /**
     * Dom\Document methods aliased from DOMDocument handlers (php-src php_dom.stub.php; #20556).
     *
     * @var list<string>
     */
    private const DOCUMENT_SHARED_METHODS = [
        'createelement',
        'createelementns',
        'createattribute',
        'createattributens',
        'createdocumentfragment',
        'createtextnode',
        'createcomment',
        'createcdatasection',
        'createprocessinginstruction',
        'getelementsbytagname',
        'getelementsbytagnamens',
        'getelementbyid',
        'importnode',
        'adoptnode',
        'registernodeclass',
        'schemavalidate',
        'schemavalidatesource',
        'relaxngvalidate',
        'relaxngvalidatesource',
        'savexml',
        // Dom\Document implements ParentNode — not inherited from Dom\Node (php_dom.stub.php; #23155).
        'append',
        'prepend',
        'replacechildren',
    ];

    /**
     * Dom\XMLDocument-only methods (php-src XMLDocument stub; #20556).
     *
     * @var list<string>
     */
    private const XML_DOCUMENT_EXTRA_METHODS = [
        'createentityreference',
        'validate',
        'xinclude',
    ];

    public static function register(Context $ctx): void
    {
        if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
            return;
        }
        // Always (re)register living constants — early return below skips class rebuild.
        DomLivingConstants::register($ctx);
        if (isset($ctx->classes[VmDomLiving::CLASS_HTML_DOCUMENT])) {
            return;
        }

        $objProto = new Variable(Variable::TYPE_OBJECT);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $pub = CfgFunc::FLAG_PUBLIC;

        // Dom\AdjacentPosition — insertAdjacent* where (php-src php_dom.stub.php; #20782).
        DomAdjacentPositionEnum::register($ctx);

        // Dom\ParentNode / Dom\ChildNode — living interfaces (php-src php_dom.stub.php; #20961).
        self::registerParentAndChildNodeInterfaces($ctx);

        // Dom\DOMException — php-src stub: legacy DOMException is @alias Dom\DOMException (#20983).
        // Share the ThrowableManifest DOMException ClassEntry (boot-time registry; #29084).
        $domException = $ctx->classes[ThrowableManifest::LC_DOM_EXCEPTION] ?? null;
        if (null !== $domException
            && !isset($ctx->classes[VmDomLiving::CLASS_DOM_EXCEPTION])
            && !isset($ctx->classAliases[VmDomLiving::CLASS_DOM_EXCEPTION])
        ) {
            $ctx->classes[VmDomLiving::CLASS_DOM_EXCEPTION] = $domException;
            $ctx->classAliases[VmDomLiving::CLASS_DOM_EXCEPTION] = ThrowableManifest::LC_DOM_EXCEPTION;
        }

        // Dom\NamespaceInfo — getInScopeNamespaces / getDescendantNamespaces entries (#20924).
        $nsInfo = new ClassEntry('Dom\\NamespaceInfo');
        $nsInfo->isInternal = true;
        $strProto = new Variable(Variable::TYPE_STRING);
        $nsInfo->properties[] = new ClassProperty(VmDomLiving::PROP_NAMESPACE_INFO_PREFIX, $nullProto, $strProto);
        $nsInfo->properties[] = new ClassProperty(VmDomLiving::PROP_NAMESPACE_INFO_NAMESPACE_URI, $nullProto, $strProto);
        $nsInfo->properties[] = new ClassProperty(VmDomLiving::PROP_NAMESPACE_INFO_ELEMENT, $nullProto, $objProto);
        // php-src php_dom.stub.php — private __construct (#26059).
        self::installPrivateConstruct($nsInfo, false);
        $ctx->classes[VmDomLiving::CLASS_NAMESPACE_INFO] = $nsInfo;

        // Dom\Implementation — Document::$implementation (php-src php_dom.stub.php; #20898, #20910).
        $impl = new ClassEntry('Dom\\Implementation');
        $impl->isInternal = true;
        self::copyMethods($ctx->classes[VmDom::CLASS_IMPLEMENTATION] ?? null, $impl);
        $impl->methods['createhtmldocument'] = new ImplementationCreateHTMLDocument();
        $impl->methodVisibility['createhtmldocument'] = $pub;
        $impl->methodNames['createhtmldocument'] = 'createHTMLDocument';
        // Override copied legacy factories with living return types (#20910).
        $impl->methods['createdocument'] = new LivingImplementationCreateDocument();
        $impl->methodVisibility['createdocument'] = $pub;
        $impl->methodNames['createdocument'] = 'createDocument';
        $impl->methods['createdocumenttype'] = new LivingImplementationCreateDocumentType();
        $impl->methodVisibility['createdocumenttype'] = $pub;
        $impl->methodNames['createdocumenttype'] = 'createDocumentType';
        $ctx->classes[VmDomLiving::CLASS_IMPLEMENTATION] = $impl;
        VmDomLiving::bindImplementationClass($impl);

        $node = new ClassEntry('Dom\\Node');
        $node->isInternal = true;
        if (CompilerVersion::supportsDomNodeIsConnected()) {
            $node->properties[] = new ClassProperty(VmDom::PROP_IS_CONNECTED, null, new Variable(Variable::TYPE_BOOLEAN));
        }
        self::copyMethods($ctx->classes[VmDom::CLASS_NODE] ?? null, $node);
        // DOCUMENT_POSITION_* live on Dom\Node as well as DOMNode (php_dom.stub.php; #26060).
        self::copyClassConstants($ctx->classes[VmDom::CLASS_NODE] ?? null, $node);
        // php-src php_dom.stub.php — private final function __construct() (#26059).
        // Subclasses inherit this via parentLc; copyMethods skips __construct so legacy
        // public DOMElement/DOMText ctors are not re-advertised on Dom\* leaves.
        self::installPrivateConstruct($node, true);
        $ctx->classes[VmDomLiving::CLASS_NODE] = $node;

        // Dom\DocumentType — living doctype nodes (php-src php_dom.stub.php; #20910).
        $documentType = new ClassEntry('Dom\\DocumentType');
        $documentType->isInternal = true;
        $documentType->parentLc = VmDomLiving::CLASS_NODE;
        $documentType->interfaces[] = VmDomLiving::CLASS_CHILD_NODE;
        $strProto = new Variable(Variable::TYPE_STRING);
        $documentType->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        $documentType->properties[] = new ClassProperty(VmDom::PROP_NAME, null, $strProto);
        $documentType->properties[] = new ClassProperty(VmDom::PROP_PUBLIC_ID, null, $strProto);
        $documentType->properties[] = new ClassProperty(VmDom::PROP_SYSTEM_ID, null, $strProto);
        $documentType->properties[] = new ClassProperty(VmDom::PROP_INTERNAL_SUBSET, $nullProto, $strProto);
        $documentType->properties[] = new ClassProperty(VmDom::PROP_ENTITIES, $nullProto, $objProto);
        $documentType->properties[] = new ClassProperty(VmDom::PROP_NOTATIONS, $nullProto, $objProto);
        // ChildNode methods — php-src implementation-alias DOMElement::{remove,before,after,replaceWith}.
        self::copySelectedMethods(
            $ctx->classes[VmDom::CLASS_ELEMENT] ?? null,
            $documentType,
            ['remove', 'before', 'after', 'replacewith']
        );
        $ctx->classes[VmDomLiving::CLASS_DOCUMENT_TYPE] = $documentType;

        // Dom\CharacterData / Text / Comment / CDATA / PI / Attr / DocumentFragment / NamedNodeMap (#20948).
        $characterData = new ClassEntry('Dom\\CharacterData');
        $characterData->isInternal = true;
        $characterData->parentLc = VmDomLiving::CLASS_NODE;
        $characterData->interfaces[] = VmDomLiving::CLASS_CHILD_NODE;
        $characterData->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        $characterData->properties[] = new ClassProperty(VmDom::PROP_DATA, null, $strProto);
        $characterData->properties[] = new ClassProperty(VmDom::PROP_LENGTH, null, new Variable(Variable::TYPE_INTEGER));
        $characterData->properties[] = new ClassProperty(VmDom::PROP_NEXT_ELEMENT_SIBLING, $nullProto, $objProto);
        $characterData->properties[] = new ClassProperty(VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, $nullProto, $objProto);
        self::copyMethods($ctx->classes[VmDom::CLASS_CHARACTER_DATA] ?? null, $characterData);
        $ctx->classes[VmDomLiving::CLASS_CHARACTER_DATA] = $characterData;

        $text = new ClassEntry('Dom\\Text');
        $text->isInternal = true;
        $text->parentLc = VmDomLiving::CLASS_CHARACTER_DATA;
        $text->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        $text->properties[] = new ClassProperty(VmDom::PROP_WHOLE_TEXT, null, $strProto);
        self::copyMethods($ctx->classes[VmDom::CLASS_TEXT] ?? null, $text);
        $ctx->classes[VmDomLiving::CLASS_TEXT] = $text;

        $cdata = new ClassEntry('Dom\\CDATASection');
        $cdata->isInternal = true;
        $cdata->parentLc = VmDomLiving::CLASS_TEXT;
        $cdata->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        self::copyMethods($ctx->classes[VmDom::CLASS_CDATA] ?? null, $cdata);
        $ctx->classes[VmDomLiving::CLASS_CDATA] = $cdata;

        $comment = new ClassEntry('Dom\\Comment');
        $comment->isInternal = true;
        $comment->parentLc = VmDomLiving::CLASS_CHARACTER_DATA;
        $comment->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        self::copyMethods($ctx->classes[VmDom::CLASS_COMMENT] ?? null, $comment);
        $ctx->classes[VmDomLiving::CLASS_COMMENT] = $comment;

        $pi = new ClassEntry('Dom\\ProcessingInstruction');
        $pi->isInternal = true;
        $pi->parentLc = VmDomLiving::CLASS_CHARACTER_DATA;
        $pi->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        $pi->properties[] = new ClassProperty(VmDom::PROP_NODE_VALUE, $nullProto, $strProto);
        $pi->properties[] = new ClassProperty(VmDom::PROP_TARGET, null, $strProto);
        $pi->properties[] = new ClassProperty(VmDom::PROP_DATA, null, $strProto);
        self::copyMethods($ctx->classes[VmDom::CLASS_PROCESSING_INSTRUCTION] ?? null, $pi);
        $ctx->classes[VmDomLiving::CLASS_PROCESSING_INSTRUCTION] = $pi;

        $attr = new ClassEntry('Dom\\Attr');
        $attr->isInternal = true;
        $attr->parentLc = VmDomLiving::CLASS_NODE;
        $attr->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        $attr->properties[] = new ClassProperty(VmDom::PROP_NAME, null, $strProto);
        $attr->properties[] = new ClassProperty(VmDom::PROP_VALUE, null, $strProto);
        $attr->properties[] = new ClassProperty(VmDom::PROP_OWNER_ELEMENT, $nullProto, $objProto);
        $specifiedDefault = new Variable(Variable::TYPE_BOOLEAN);
        $specifiedDefault->bool(true);
        $attr->properties[] = new ClassProperty(VmDom::PROP_SPECIFIED, $specifiedDefault, new Variable(Variable::TYPE_BOOLEAN));
        self::copyMethods($ctx->classes[VmDom::CLASS_ATTR] ?? null, $attr);
        // Dom\Attr::rename — php-src @implementation-alias Dom\Element::rename (#21083).
        $attr->methods['rename'] = new AttrRename();
        $attr->methodVisibility['rename'] = $pub;
        $attr->methodNames['rename'] = 'rename';
        $ctx->classes[VmDomLiving::CLASS_ATTR] = $attr;

        $fragment = new ClassEntry('Dom\\DocumentFragment');
        $fragment->isInternal = true;
        $fragment->parentLc = VmDomLiving::CLASS_NODE;
        $fragment->interfaces[] = VmDomLiving::CLASS_PARENT_NODE;
        $fragment->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        $fragment->properties[] = new ClassProperty(VmDom::PROP_FIRST_ELEMENT_CHILD, $nullProto, $objProto);
        $fragment->properties[] = new ClassProperty(VmDom::PROP_LAST_ELEMENT_CHILD, $nullProto, $objProto);
        $fragment->properties[] = new ClassProperty(VmDom::PROP_CHILD_ELEMENT_COUNT, null, new Variable(Variable::TYPE_INTEGER));
        // ParentNode::$children — PHP 8.5+ only (Zend 8.4.23 undefined; #21559, re-#21033).
        if (CompilerVersion::supportsDomParentNodeChildren()) {
            $fragment->properties[] = new ClassProperty(VmDom::PROP_CHILDREN, $nullProto, $objProto);
        }
        self::copyMethods($ctx->classes[VmDom::CLASS_DOCUMENT_FRAGMENT] ?? null, $fragment);
        // Living fragment also exposes Element querySelector* (#20948 / php_dom.stub.php).
        $fragment->methods['queryselector'] = new ElementQuerySelector();
        $fragment->methodVisibility['queryselector'] = $pub;
        $fragment->methodNames['queryselector'] = 'querySelector';
        $fragment->methods['queryselectorall'] = new ElementQuerySelectorAll();
        $fragment->methodVisibility['queryselectorall'] = $pub;
        $fragment->methodNames['queryselectorall'] = 'querySelectorAll';
        $ctx->classes[VmDomLiving::CLASS_DOCUMENT_FRAGMENT] = $fragment;

        $namedNodeMap = new ClassEntry('Dom\\NamedNodeMap');
        $namedNodeMap->isInternal = true;
        $namedNodeMap->interfaces[] = 'countable';
        if (isset($ctx->classes['iteratoraggregate'])) {
            $namedNodeMap->interfaces[] = 'iteratoraggregate';
        }
        if (isset($ctx->classes['traversable'])) {
            $namedNodeMap->interfaces[] = 'traversable';
        }
        $namedNodeMap->properties[] = new ClassProperty(VmDom::PROP_LENGTH, null, new Variable(Variable::TYPE_INTEGER));
        self::copyMethods($ctx->classes[VmDom::CLASS_NAMED_NODE_MAP] ?? null, $namedNodeMap);
        $ctx->classes[VmDomLiving::CLASS_NAMED_NODE_MAP] = $namedNodeMap;

        // Dom\DtdNamedNodeMap — DocumentType::$entities / $notations (php_dom.stub.php; #21014).
        $dtdNamedNodeMap = new ClassEntry('Dom\\DtdNamedNodeMap');
        $dtdNamedNodeMap->isInternal = true;
        $dtdNamedNodeMap->interfaces[] = 'countable';
        if (isset($ctx->classes['iteratoraggregate'])) {
            $dtdNamedNodeMap->interfaces[] = 'iteratoraggregate';
        }
        if (isset($ctx->classes['traversable'])) {
            $dtdNamedNodeMap->interfaces[] = 'traversable';
        }
        $dtdNamedNodeMap->properties[] = new ClassProperty(VmDom::PROP_LENGTH, null, new Variable(Variable::TYPE_INTEGER));
        self::copyMethods($ctx->classes[VmDom::CLASS_NAMED_NODE_MAP] ?? null, $dtdNamedNodeMap);
        $ctx->classes[VmDomLiving::CLASS_DTD_NAMED_NODE_MAP] = $dtdNamedNodeMap;

        // Dom\Entity / EntityReference / Notation — DTD leaf nodes (#20983).
        $entity = new ClassEntry('Dom\\Entity');
        $entity->isInternal = true;
        $entity->parentLc = VmDomLiving::CLASS_NODE;
        $entity->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        $entity->properties[] = new ClassProperty(VmDom::PROP_PUBLIC_ID, $nullProto, $strProto);
        $entity->properties[] = new ClassProperty(VmDom::PROP_SYSTEM_ID, $nullProto, $strProto);
        $entity->properties[] = new ClassProperty(VmDom::PROP_NOTATION_NAME, $nullProto, $strProto);
        self::copyMethods($ctx->classes[VmDom::CLASS_ENTITY] ?? null, $entity);
        $ctx->classes[VmDomLiving::CLASS_ENTITY] = $entity;

        $entityRef = new ClassEntry('Dom\\EntityReference');
        $entityRef->isInternal = true;
        $entityRef->parentLc = VmDomLiving::CLASS_NODE;
        $entityRef->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        self::copyMethods($ctx->classes[VmDom::CLASS_ENTITY_REFERENCE] ?? null, $entityRef);
        $ctx->classes[VmDomLiving::CLASS_ENTITY_REFERENCE] = $entityRef;

        $notation = new ClassEntry('Dom\\Notation');
        $notation->isInternal = true;
        $notation->parentLc = VmDomLiving::CLASS_NODE;
        $notation->properties[] = new ClassProperty(VmDom::PROP_NODE_NAME, null, $strProto);
        $notation->properties[] = new ClassProperty(VmDom::PROP_PUBLIC_ID, $nullProto, $strProto);
        $notation->properties[] = new ClassProperty(VmDom::PROP_SYSTEM_ID, $nullProto, $strProto);
        self::copyMethods($ctx->classes[VmDom::CLASS_NOTATION] ?? null, $notation);
        $ctx->classes[VmDomLiving::CLASS_NOTATION] = $notation;

        $element = new ClassEntry('Dom\\Element');
        $element->isInternal = true;
        $element->parentLc = VmDomLiving::CLASS_NODE;
        $element->interfaces[] = VmDomLiving::CLASS_PARENT_NODE;
        $element->interfaces[] = VmDomLiving::CLASS_CHILD_NODE;
        $element->properties[] = new ClassProperty(VmDom::PROP_TEXT_CONTENT, $nullProto, new Variable(Variable::TYPE_STRING));
        // ParentNode::$children — PHP 8.5+ only (Zend 8.4.23 undefined; #21559, re-#21033).
        if (CompilerVersion::supportsDomParentNodeChildren()) {
            $element->properties[] = new ClassProperty(VmDom::PROP_CHILDREN, $nullProto, $objProto);
        }
        // Living Standard string props (php-src php_dom.stub.php Dom\Element; #20532).
        // $outerHTML is PHP 8.5+ only (Zend 8.4.x undefined; #22482, re-#20532).
        $strProto = new Variable(Variable::TYPE_STRING);
        $element->properties[] = new ClassProperty(DomHtmlElementPropertySupport::PROP_ID, $nullProto, $strProto);
        $element->properties[] = new ClassProperty(DomHtmlElementPropertySupport::PROP_CLASS_NAME, $nullProto, $strProto);
        $element->properties[] = new ClassProperty(DomHtmlElementPropertySupport::PROP_INNER_HTML, $nullProto, $strProto);
        if (CompilerVersion::supportsDomElementOuterHtmlProperty()) {
            $element->properties[] = new ClassProperty(DomHtmlElementPropertySupport::PROP_OUTER_HTML, $nullProto, $strProto);
        }
        // php-src Dom\Element::$substitutedNodeValue (ext/dom/element.c; #21034).
        $element->properties[] = new ClassProperty(
            DomHtmlElementPropertySupport::PROP_SUBSTITUTED_NODE_VALUE,
            $nullProto,
            $strProto
        );
        if (CompilerVersion::supportsDomTokenList()) {
            $element->properties[] = new ClassProperty(VmDom::PROP_CLASS_LIST, $nullProto, $objProto);
        }
        self::copyMethods($ctx->classes[VmDom::CLASS_ELEMENT] ?? null, $element);
        $element->methods['closest'] = new ElementClosest();
        $element->methodVisibility['closest'] = $pub;
        $element->methodNames['closest'] = 'closest';
        $element->methods['matches'] = new ElementMatches();
        $element->methodVisibility['matches'] = $pub;
        $element->methodNames['matches'] = 'matches';
        $element->methods['queryselector'] = new ElementQuerySelector();
        $element->methodVisibility['queryselector'] = $pub;
        $element->methodNames['queryselector'] = 'querySelector';
        $element->methods['queryselectorall'] = new ElementQuerySelectorAll();
        $element->methodVisibility['queryselectorall'] = $pub;
        $element->methodNames['queryselectorall'] = 'querySelectorAll';
        // PHP 8.5+ only (php-src PHP-8.5 UPGRADING / php_dom.stub.php; #27593).
        if (CompilerVersion::supportsDomElementGetElementsByClassName()) {
            $element->methods['getelementsbyclassname'] = new ElementGetElementsByClassName();
            $element->methodVisibility['getelementsbyclassname'] = $pub;
            $element->methodNames['getelementsbyclassname'] = 'getElementsByClassName';
        }
        $element->methods['getinscopenamespaces'] = new ElementGetInScopeNamespaces();
        $element->methodVisibility['getinscopenamespaces'] = $pub;
        $element->methodNames['getinscopenamespaces'] = 'getInScopeNamespaces';
        $element->methods['getdescendantnamespaces'] = new ElementGetDescendantNamespaces();
        $element->methodVisibility['getdescendantnamespaces'] = $pub;
        $element->methodNames['getdescendantnamespaces'] = 'getDescendantNamespaces';
        $element->methods['rename'] = new ElementRename();
        $element->methodVisibility['rename'] = $pub;
        $element->methodNames['rename'] = 'rename';
        // php-src php_dom.stub.php — Dom\Element attribute getters are nullable (#26065).
        self::applyElementAttributeGetterReturnTypes($element);
        // php-src php_dom.stub.php — Element selector / getElementsByTagName returns (#28741).
        self::applyElementSelectorReturnTypes($element);
        $ctx->classes[VmDomLiving::CLASS_ELEMENT] = $element;

        $htmlElement = new ClassEntry('Dom\\HTMLElement');
        $htmlElement->isInternal = true;
        $htmlElement->parentLc = VmDomLiving::CLASS_ELEMENT;
        $ctx->classes[VmDomLiving::CLASS_HTML_ELEMENT] = $htmlElement;

        if (CompilerVersion::supportsDomTokenList()) {
            // php-src Dom\TokenList only — no legacy DOMTokenList class (#28227, re-#20512, #20884).
            $tokenList = new ClassEntry('Dom\\TokenList');
            $tokenList->isInternal = true;
            $tokenList->interfaces[] = 'countable';
            if (isset($ctx->classes['iteratoraggregate'])) {
                $tokenList->interfaces[] = 'iteratoraggregate';
            }
            if (isset($ctx->classes['traversable'])) {
                $tokenList->interfaces[] = 'traversable';
            }
            $tokenList->properties[] = new ClassProperty(VmDom::PROP_LENGTH, null, new Variable(Variable::TYPE_INTEGER));
            $tokenList->properties[] = new ClassProperty(VmDom::PROP_VALUE, null, new Variable(Variable::TYPE_STRING));
            // php-src php_dom.stub.php Dom\TokenList — getIterator only; no entries/keys/values/forEach
            // and no __toString ((string) throws Error on Zend 8.4/8.5; #26721, re-#24545).
            $tokenList->methods['add'] = new TokenListAdd();
            $tokenList->methodVisibility['add'] = $pub;
            $tokenList->methods['remove'] = new TokenListRemove();
            $tokenList->methodVisibility['remove'] = $pub;
            $tokenList->methods['contains'] = new TokenListContains();
            $tokenList->methodVisibility['contains'] = $pub;
            $tokenList->methods['toggle'] = new TokenListToggle();
            $tokenList->methodVisibility['toggle'] = $pub;
            $tokenList->methods['item'] = new TokenListItem();
            $tokenList->methodVisibility['item'] = $pub;
            $tokenList->methods['replace'] = new TokenListReplace();
            $tokenList->methodVisibility['replace'] = $pub;
            $tokenList->methods['supports'] = new TokenListSupports();
            $tokenList->methodVisibility['supports'] = $pub;
            $tokenList->methods['count'] = new TokenListCount();
            $tokenList->methodVisibility['count'] = $pub;
            $tokenList->methods['getiterator'] = new TokenListGetIterator();
            $tokenList->methodVisibility['getiterator'] = $pub;
            $tokenList->methodNames['getiterator'] = 'getIterator';
            // php-src php_dom.stub.php — private __construct (not final; #26059).
            self::installPrivateConstruct($tokenList, false);
            $ctx->classes[VmDomLiving::CLASS_TOKEN_LIST] = $tokenList;
        }

        // Dom\HTMLCollection — live class/tag/$children collections (php-src html_collection.c; #20709).
        $htmlCollection = new ClassEntry('Dom\\HTMLCollection');
        $htmlCollection->isInternal = true;
        $htmlCollection->interfaces[] = 'countable';
        if (isset($ctx->classes['iteratoraggregate'])) {
            $htmlCollection->interfaces[] = 'iteratoraggregate';
        }
        if (isset($ctx->classes['traversable'])) {
            $htmlCollection->interfaces[] = 'traversable';
        }
        $htmlCollection->properties[] = new ClassProperty(VmDom::PROP_LENGTH, null, new Variable(Variable::TYPE_INTEGER));
        self::copyMethods($ctx->classes[VmDom::CLASS_NODE_LIST] ?? null, $htmlCollection);
        $htmlCollection->methods['nameditem'] = new HtmlCollectionNamedItem();
        $htmlCollection->methodVisibility['nameditem'] = $pub;
        $htmlCollection->methodNames['nameditem'] = 'namedItem';
        $ctx->classes[VmDomLiving::CLASS_HTML_COLLECTION] = $htmlCollection;

        // Dom\NodeList — XPath node-set results (php-src php_dom.stub.php; #20757).
        $nodeList = new ClassEntry('Dom\\NodeList');
        $nodeList->isInternal = true;
        $nodeList->interfaces[] = 'countable';
        if (isset($ctx->classes['iteratoraggregate'])) {
            $nodeList->interfaces[] = 'iteratoraggregate';
        }
        if (isset($ctx->classes['traversable'])) {
            $nodeList->interfaces[] = 'traversable';
        }
        $nodeList->properties[] = new ClassProperty(VmDom::PROP_LENGTH, null, new Variable(Variable::TYPE_INTEGER));
        self::copyMethods($ctx->classes[VmDom::CLASS_NODE_LIST] ?? null, $nodeList);
        $ctx->classes[VmDomLiving::CLASS_NODE_LIST] = $nodeList;

        // Dom\XPath — living Document XPath (php-src php_dom.stub.php / xpath.c; #20757).
        $xpath = new ClassEntry('Dom\\XPath');
        $xpath->isInternal = true;
        $xpathConstruct = new LivingXPathConstruct();
        $xpath->constructor = $xpathConstruct;
        $xpath->methods['__construct'] = $xpathConstruct;
        $xpath->methodVisibility['__construct'] = $pub;
        self::copyMethods($ctx->classes[VmDom::CLASS_XPATH] ?? null, $xpath);
        $ctx->classes[VmDomLiving::CLASS_XPATH] = $xpath;

        $document = new ClassEntry('Dom\\Document');
        $document->isInternal = true;
        // php-src php_dom.stub.php — abstract class Dom\Document (#26059).
        $document->isAbstract = true;
        $document->parentLc = VmDomLiving::CLASS_NODE;
        $document->interfaces[] = VmDomLiving::CLASS_PARENT_NODE;
        $document->properties[] = new ClassProperty(VmDomLiving::PROP_DOCUMENT_ELEMENT, $nullProto, $objProto);
        // ParentNode::$children — PHP 8.5+ only (Zend 8.4.23 undefined; #21559, re-#21033).
        if (CompilerVersion::supportsDomParentNodeChildren()) {
            $document->properties[] = new ClassProperty(VmDom::PROP_CHILDREN, $nullProto, $objProto);
        }
        self::copySelectedMethods(
            $ctx->classes[VmDom::CLASS_DOCUMENT] ?? null,
            $document,
            self::DOCUMENT_SHARED_METHODS
        );
        // Living Dom casing (php-src HTMLDocument/XMLDocument::saveXml; #20556).
        $document->methodNames['savexml'] = 'saveXml';
        // php-src php_dom.stub.php — Document instance Reflection (#28740).
        $elementRet = ReflectionTypeSupport::cfgTypeFromLabel('?Dom\\Element');
        if (null !== $elementRet) {
            $document->methodReturnDeclaredTypes['getelementbyid'] = $elementRet;
        }
        $saveXmlRet = ReflectionTypeSupport::cfgTypeFromLabel('string|false');
        if (null !== $saveXmlRet) {
            $document->methodReturnDeclaredTypes['savexml'] = $saveXmlRet;
        }
        // Document alias of Element::getElementsByClassName — PHP 8.5+ (#27593).
        if (CompilerVersion::supportsDomElementGetElementsByClassName()) {
            $document->methods['getelementsbyclassname'] = new DocumentGetElementsByClassName();
            $document->methodVisibility['getelementsbyclassname'] = $pub;
            $document->methodNames['getelementsbyclassname'] = 'getElementsByClassName';
        }
        // Dom\Document::importLegacyNode — legacy DOM* → living Dom\* (#20940).
        $document->methods['importlegacynode'] = new DocumentImportLegacyNode();
        $document->methodVisibility['importlegacynode'] = $pub;
        $document->methodNames['importlegacynode'] = 'importLegacyNode';
        $ctx->classes[VmDomLiving::CLASS_DOCUMENT] = $document;

        $htmlDocument = new ClassEntry('Dom\\HTMLDocument');
        $htmlDocument->isInternal = true;
        $htmlDocument->parentLc = VmDomLiving::CLASS_DOCUMENT;
        $htmlDocument->properties[] = new ClassProperty(VmDomLiving::PROP_BODY, $nullProto, $objProto);
        $htmlDocument->properties[] = new ClassProperty(VmDomLiving::PROP_HEAD, $nullProto, $objProto);
        $htmlDocument->properties[] = new ClassProperty(VmDomLiving::PROP_DOCUMENT_ELEMENT, $nullProto, $objProto);
        $htmlDocument->methods['createfromstring'] = new HtmlDocumentCreateFromString();
        $htmlDocument->methodVisibility['createfromstring'] = $pubStatic;
        $htmlDocument->methodNames['createfromstring'] = 'createFromString';
        $htmlDocument->methods['createempty'] = new HtmlDocumentCreateEmpty();
        $htmlDocument->methodVisibility['createempty'] = $pubStatic;
        $htmlDocument->methodNames['createempty'] = 'createEmpty';
        $htmlDocument->methods['createfromfile'] = new HtmlDocumentCreateFromFile();
        $htmlDocument->methodVisibility['createfromfile'] = $pubStatic;
        $htmlDocument->methodNames['createfromfile'] = 'createFromFile';
        // php-src stub returns HTMLDocument (#26080 / #27924).
        $htmlDocRet = ReflectionTypeSupport::cfgTypeFromLabel('Dom\\HTMLDocument');
        if (null !== $htmlDocRet) {
            $htmlDocument->methodReturnDeclaredTypes['createfromstring'] = $htmlDocRet;
            $htmlDocument->methodReturnDeclaredTypes['createfromfile'] = $htmlDocRet;
            $htmlDocument->methodReturnDeclaredTypes['createempty'] = $htmlDocRet;
        }
        $htmlDocument->methods['getelementbyid'] = new HtmlDocumentGetElementById();
        $htmlDocument->methodVisibility['getelementbyid'] = CfgFunc::FLAG_PUBLIC;
        $htmlDocument->methodNames['getelementbyid'] = 'getElementById';
        $htmlDocument->methods['queryselector'] = new HtmlDocumentQuerySelector();
        $htmlDocument->methodVisibility['queryselector'] = CfgFunc::FLAG_PUBLIC;
        $htmlDocument->methodNames['queryselector'] = 'querySelector';
        $htmlDocument->methods['queryselectorall'] = new HtmlDocumentQuerySelectorAll();
        $htmlDocument->methodVisibility['queryselectorall'] = CfgFunc::FLAG_PUBLIC;
        $htmlDocument->methodNames['queryselectorall'] = 'querySelectorAll';
        // ParentNode selector returns (php-src php_dom.stub.php; #28741).
        if (null !== $elementRet) {
            $htmlDocument->methodReturnDeclaredTypes['queryselector'] = $elementRet;
        }
        $nodeListRet = ReflectionTypeSupport::cfgTypeFromLabel('Dom\\NodeList');
        if (null !== $nodeListRet) {
            $htmlDocument->methodReturnDeclaredTypes['queryselectorall'] = $nodeListRet;
        }
        $htmlDocument->methods['savehtml'] = new HtmlDocumentSaveHtml();
        $htmlDocument->methodVisibility['savehtml'] = CfgFunc::FLAG_PUBLIC;
        $htmlDocument->methodNames['savehtml'] = 'saveHtml';
        // php-src php_dom.stub.php — HTMLDocument instance Reflection (#28740).
        if (null !== $elementRet) {
            $htmlDocument->methodReturnDeclaredTypes['getelementbyid'] = $elementRet;
        }
        $stringRet = ReflectionTypeSupport::cfgTypeFromLabel('string');
        if (null !== $stringRet) {
            $htmlDocument->methodReturnDeclaredTypes['savehtml'] = $stringRet;
        }
        if (null !== $saveXmlRet) {
            $htmlDocument->methodReturnDeclaredTypes['savexml'] = $saveXmlRet;
        }
        self::copySelectedMethods(
            $ctx->classes[VmDom::CLASS_DOCUMENT] ?? null,
            $htmlDocument,
            ['savehtmlfile']
        );
        $htmlDocument->methodNames['savehtmlfile'] = 'saveHtmlFile';
        $legacyDoc = $ctx->classes[VmDom::CLASS_DOCUMENT] ?? null;
        if (null !== $legacyDoc && isset($legacyDoc->methods['save'])) {
            $htmlDocument->methods['savexmlfile'] = $legacyDoc->methods['save'];
            $htmlDocument->methodVisibility['savexmlfile'] = $pub;
            $htmlDocument->methodNames['savexmlfile'] = 'saveXmlFile';
        }
        $ctx->classes[VmDomLiving::CLASS_HTML_DOCUMENT] = $htmlDocument;

        $xmlDocument = new ClassEntry('Dom\\XMLDocument');
        $xmlDocument->isInternal = true;
        $xmlDocument->parentLc = VmDomLiving::CLASS_DOCUMENT;
        $xmlDocument->properties[] = new ClassProperty(VmDomLiving::PROP_DOCUMENT_ELEMENT, $nullProto, $objProto);
        $xmlDocument->properties[] = new ClassProperty(
            VmDom::PROP_FORMAT_OUTPUT,
            null,
            new Variable(Variable::TYPE_BOOLEAN)
        );
        $xmlDocument->methods['createfromstring'] = new XmlDocumentCreateFromString();
        $xmlDocument->methodVisibility['createfromstring'] = $pubStatic;
        $xmlDocument->methodNames['createfromstring'] = 'createFromString';
        $xmlDocument->methods['createfromfile'] = new XmlDocumentCreateFromFile();
        $xmlDocument->methodVisibility['createfromfile'] = $pubStatic;
        $xmlDocument->methodNames['createfromfile'] = 'createFromFile';
        $xmlDocument->methods['createempty'] = new XmlDocumentCreateEmpty();
        $xmlDocument->methodVisibility['createempty'] = $pubStatic;
        $xmlDocument->methodNames['createempty'] = 'createEmpty';
        // php-src stub returns XMLDocument (#26080 / #27924).
        $xmlDocRet = ReflectionTypeSupport::cfgTypeFromLabel('Dom\\XMLDocument');
        if (null !== $xmlDocRet) {
            $xmlDocument->methodReturnDeclaredTypes['createfromstring'] = $xmlDocRet;
            $xmlDocument->methodReturnDeclaredTypes['createfromfile'] = $xmlDocRet;
            $xmlDocument->methodReturnDeclaredTypes['createempty'] = $xmlDocRet;
        }
        // Inherited getElementById / saveXml Reflection when looked up on XMLDocument (#28740).
        if (null !== $elementRet) {
            $xmlDocument->methodReturnDeclaredTypes['getelementbyid'] = $elementRet;
        }
        if (null !== $saveXmlRet) {
            $xmlDocument->methodReturnDeclaredTypes['savexml'] = $saveXmlRet;
        }
        self::copySelectedMethods(
            $ctx->classes[VmDom::CLASS_DOCUMENT] ?? null,
            $xmlDocument,
            self::XML_DOCUMENT_EXTRA_METHODS
        );
        if (null !== $legacyDoc && isset($legacyDoc->methods['save'])) {
            $xmlDocument->methods['savexmlfile'] = $legacyDoc->methods['save'];
            $xmlDocument->methodVisibility['savexmlfile'] = $pub;
            $xmlDocument->methodNames['savexmlfile'] = 'saveXmlFile';
        }
        $ctx->classes[VmDomLiving::CLASS_XML_DOCUMENT] = $xmlDocument;

        // Living Dom\* classes do NOT set ZEND_ACC_NO_DYNAMIC_PROPERTIES on php-src 8.4/8.5
        // (Deprecated + allow write, same as legacy DOM*). #26055/#26371 sealed them in error;
        // corrected in #26566 after re-probe vs php:8.4.24 / 8.5.8.
    }

    /**
     * Register Dom\ParentNode / Dom\ChildNode (php-src ext/dom/php_dom.stub.php; #20961).
     *
     * Interfaces are independent (ChildNode does not extend ParentNode — same as classic
     * DOMParentNode / DOMChildNode in php_dom.stub.php; #22389).
     */
    private static function registerParentAndChildNodeInterfaces(Context $ctx): void
    {
        if (!isset($ctx->classes[VmDomLiving::CLASS_PARENT_NODE])) {
            $parentNode = new ClassEntry('Dom\\ParentNode');
            $parentNode->isInternal = true;
            $parentNode->isInterface = true;
            BuiltinClasses::registerBuiltinInterfaceMethods($parentNode, [
                'append',
                'prepend',
                'replaceChildren',
                'querySelector',
                'querySelectorAll',
            ]);
            $ctx->classes[VmDomLiving::CLASS_PARENT_NODE] = $parentNode;
        }
        if (!isset($ctx->classes[VmDomLiving::CLASS_CHILD_NODE])) {
            $childNode = new ClassEntry('Dom\\ChildNode');
            $childNode->isInternal = true;
            $childNode->isInterface = true;
            BuiltinClasses::registerBuiltinInterfaceMethods($childNode, [
                'remove',
                'before',
                'after',
                'replaceWith',
            ]);
            $ctx->classes[VmDomLiving::CLASS_CHILD_NODE] = $childNode;
        }
    }

    /**
     * Install Dom\ non-user-constructible __construct (php-src php_dom.stub.php; #26059).
     *
     * @param bool $final true → private final (Dom\Node); false → private only (TokenList / NamespaceInfo)
     */
    private static function installPrivateConstruct(ClassEntry $entry, bool $final): void
    {
        $ctor = new LivingPrivateConstruct();
        $vis = CfgFunc::FLAG_PRIVATE;
        if ($final) {
            $vis |= CfgFunc::FLAG_FINAL;
        }
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $vis;
        $entry->methodNames['__construct'] = '__construct';
        $entry->methodDeclaringClassLc['__construct'] = strtolower($entry->name);
    }

    /** Share classic DOM* method handlers with living Dom\* types (#20418). */
    /**
     * php-src ext/dom/php_dom.stub.php — Dom\Element attribute getter return types (#26065).
     *
     * Living Standard missing-attr nullability (`?string` / `?Attr`); legacy DOMElement keeps
     * tentative `string` / untyped node returns and is not updated here.
     */
    private static function applyElementAttributeGetterReturnTypes(ClassEntry $element): void
    {
        $returns = [
            'getattribute' => '?string',
            'getattributens' => '?string',
            // Stub `?Attr` resolves to Dom\Attr (ReflectionNamedType FQCN).
            'getattributenode' => '?Dom\\Attr',
            'getattributenodens' => '?Dom\\Attr',
        ];
        foreach ($returns as $methodLc => $label) {
            $type = ReflectionTypeSupport::cfgTypeFromLabel($label);
            if (null !== $type) {
                $element->methodReturnDeclaredTypes[$methodLc] = $type;
            }
        }
    }

    /**
     * php-src ext/dom/php_dom.stub.php — Dom\Element CSS / tag-name query returns (#28741).
     */
    private static function applyElementSelectorReturnTypes(ClassEntry $element): void
    {
        $returns = [
            'queryselector' => '?Dom\\Element',
            'queryselectorall' => 'Dom\\NodeList',
            'closest' => '?Dom\\Element',
            'matches' => 'bool',
            'getelementsbytagname' => 'Dom\\HTMLCollection',
        ];
        foreach ($returns as $methodLc => $label) {
            $type = ReflectionTypeSupport::cfgTypeFromLabel($label);
            if (null !== $type) {
                $element->methodReturnDeclaredTypes[$methodLc] = $type;
            }
        }
    }

    private static function copyMethods(?ClassEntry $from, ClassEntry $to): void
    {
        if (null === $from) {
            return;
        }
        foreach ($from->methods as $lc => $method) {
            // Never copy legacy public __construct onto Dom\* — Node's private final wins (#26059).
            if ('__construct' === $lc) {
                continue;
            }
            if (isset($to->methods[$lc])) {
                continue;
            }
            $to->methods[$lc] = $method;
            if (isset($from->methodVisibility[$lc])) {
                $to->methodVisibility[$lc] = $from->methodVisibility[$lc];
            }
            if (isset($from->methodNames[$lc])) {
                $to->methodNames[$lc] = $from->methodNames[$lc];
            } elseif (!isset($to->methodNames[$lc])) {
                $declared = $method->getName();
                if (str_contains($declared, '::')) {
                    $declared = substr($declared, strrpos($declared, '::') + 2);
                }
                $to->methodNames[$lc] = $declared;
            }
        }
    }

    /** Copy class constants (case-sensitive keys) onto living Dom\* types (#26060). */
    private static function copyClassConstants(?ClassEntry $from, ClassEntry $to): void
    {
        if (null === $from) {
            return;
        }
        foreach ($from->constants as $key => $value) {
            if (isset($to->constants[$key])) {
                continue;
            }
            $to->constants[$key] = $value;
            if (isset($from->constNames[$key])) {
                $to->constNames[$key] = $from->constNames[$key];
            }
            if (isset($from->constVisibility[$key])) {
                $to->constVisibility[$key] = $from->constVisibility[$key];
            }
        }
    }

    /**
     * @param list<string> $methodLcs
     */
    private static function copySelectedMethods(?ClassEntry $from, ClassEntry $to, array $methodLcs): void
    {
        if (null === $from) {
            return;
        }
        foreach ($methodLcs as $lc) {
            if ('__construct' === $lc) {
                continue;
            }
            if (!isset($from->methods[$lc]) || isset($to->methods[$lc])) {
                continue;
            }
            $to->methods[$lc] = $from->methods[$lc];
            if (isset($from->methodVisibility[$lc])) {
                $to->methodVisibility[$lc] = $from->methodVisibility[$lc];
            }
            if (isset($from->methodNames[$lc])) {
                $to->methodNames[$lc] = $from->methodNames[$lc];
            } elseif (!isset($to->methodNames[$lc])) {
                $declared = $from->methods[$lc]->getName();
                if (str_contains($declared, '::')) {
                    $declared = substr($declared, strrpos($declared, '::') + 2);
                }
                $to->methodNames[$lc] = $declared;
            }
        }
    }
}
