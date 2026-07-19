<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
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
        self::registerAdjacentPositionEnum($ctx);
        if (isset($ctx->classes[VmDomLiving::CLASS_HTML_DOCUMENT])) {
            return;
        }

        $objProto = new Variable(Variable::TYPE_OBJECT);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $pub = CfgFunc::FLAG_PUBLIC;

        $node = new ClassEntry('Dom\\Node');
        $node->isInternal = true;
        if (CompilerVersion::supportsDomNodeIsConnected()) {
            $node->properties[] = new ClassProperty(VmDom::PROP_IS_CONNECTED, null, new Variable(Variable::TYPE_BOOLEAN));
        }
        self::copyMethods($ctx->classes[VmDom::CLASS_NODE] ?? null, $node);
        $ctx->classes[VmDomLiving::CLASS_NODE] = $node;

        $element = new ClassEntry('Dom\\Element');
        $element->isInternal = true;
        $element->parentLc = VmDomLiving::CLASS_NODE;
        $element->properties[] = new ClassProperty(VmDom::PROP_TEXT_CONTENT, $nullProto, new Variable(Variable::TYPE_STRING));
        // Living Standard string props (php-src php_dom.stub.php Dom\Element; #20532).
        $strProto = new Variable(Variable::TYPE_STRING);
        $element->properties[] = new ClassProperty(DomHtmlElementPropertySupport::PROP_ID, $nullProto, $strProto);
        $element->properties[] = new ClassProperty(DomHtmlElementPropertySupport::PROP_CLASS_NAME, $nullProto, $strProto);
        $element->properties[] = new ClassProperty(DomHtmlElementPropertySupport::PROP_INNER_HTML, $nullProto, $strProto);
        $element->properties[] = new ClassProperty(DomHtmlElementPropertySupport::PROP_OUTER_HTML, $nullProto, $strProto);
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
        $element->methods['getelementsbyclassname'] = new ElementGetElementsByClassName();
        $element->methodVisibility['getelementsbyclassname'] = $pub;
        $element->methodNames['getelementsbyclassname'] = 'getElementsByClassName';
        $ctx->classes[VmDomLiving::CLASS_ELEMENT] = $element;

        $htmlElement = new ClassEntry('Dom\\HTMLElement');
        $htmlElement->isInternal = true;
        $htmlElement->parentLc = VmDomLiving::CLASS_ELEMENT;
        $ctx->classes[VmDomLiving::CLASS_HTML_ELEMENT] = $htmlElement;

        if (CompilerVersion::supportsDomTokenList()) {
            // Dom\TokenList is parallel to legacy DOMTokenList (php-src php_dom.stub.php; #20512).
            $tokenList = new ClassEntry('Dom\\TokenList');
            $tokenList->isInternal = true;
            $tokenList->interfaces[] = 'countable';
            $tokenList->properties[] = new ClassProperty(VmDom::PROP_LENGTH, null, new Variable(Variable::TYPE_INTEGER));
            $tokenList->properties[] = new ClassProperty(VmDom::PROP_VALUE, null, new Variable(Variable::TYPE_STRING));
            self::copyMethods($ctx->classes[VmDom::CLASS_TOKEN_LIST] ?? null, $tokenList);
            $ctx->classes[VmDomLiving::CLASS_TOKEN_LIST] = $tokenList;
        }

        // Dom\HTMLCollection — live class/tag/$children collections (php-src html_collection.c; #20709).
        $htmlCollection = new ClassEntry('Dom\\HTMLCollection');
        $htmlCollection->isInternal = true;
        $htmlCollection->interfaces[] = 'countable';
        if (isset($ctx->classes['iterator'])) {
            $htmlCollection->interfaces[] = 'iterator';
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
        if (isset($ctx->classes['iterator'])) {
            $nodeList->interfaces[] = 'iterator';
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
        $document->parentLc = VmDomLiving::CLASS_NODE;
        $document->properties[] = new ClassProperty(VmDomLiving::PROP_DOCUMENT_ELEMENT, $nullProto, $objProto);
        self::copySelectedMethods(
            $ctx->classes[VmDom::CLASS_DOCUMENT] ?? null,
            $document,
            self::DOCUMENT_SHARED_METHODS
        );
        // Living Dom casing (php-src HTMLDocument/XMLDocument::saveXml; #20556).
        $document->methodNames['savexml'] = 'saveXml';
        $document->methods['getelementsbyclassname'] = new DocumentGetElementsByClassName();
        $document->methodVisibility['getelementsbyclassname'] = $pub;
        $document->methodNames['getelementsbyclassname'] = 'getElementsByClassName';
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
        $htmlDocument->methods['getelementbyid'] = new HtmlDocumentGetElementById();
        $htmlDocument->methodVisibility['getelementbyid'] = CfgFunc::FLAG_PUBLIC;
        $htmlDocument->methodNames['getelementbyid'] = 'getElementById';
        $htmlDocument->methods['queryselector'] = new HtmlDocumentQuerySelector();
        $htmlDocument->methodVisibility['queryselector'] = CfgFunc::FLAG_PUBLIC;
        $htmlDocument->methodNames['queryselector'] = 'querySelector';
        $htmlDocument->methods['queryselectorall'] = new HtmlDocumentQuerySelectorAll();
        $htmlDocument->methodVisibility['queryselectorall'] = CfgFunc::FLAG_PUBLIC;
        $htmlDocument->methodNames['queryselectorall'] = 'querySelectorAll';
        $htmlDocument->methods['savehtml'] = new HtmlDocumentSaveHtml();
        $htmlDocument->methodVisibility['savehtml'] = CfgFunc::FLAG_PUBLIC;
        $htmlDocument->methodNames['savehtml'] = 'saveHtml';
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
        $xmlDocument->methods['createfromstring'] = new XmlDocumentCreateFromString();
        $xmlDocument->methodVisibility['createfromstring'] = $pubStatic;
        $xmlDocument->methodNames['createfromstring'] = 'createFromString';
        $xmlDocument->methods['createempty'] = new XmlDocumentCreateEmpty();
        $xmlDocument->methodVisibility['createempty'] = $pubStatic;
        $xmlDocument->methodNames['createempty'] = 'createEmpty';
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
    }

    /**
     * Dom\AdjacentPosition — string-backed insertAdjacent* positions (php_dom.stub.php; #20782).
     */
    private static function registerAdjacentPositionEnum(Context $ctx): void
    {
        $lc = VmDomLiving::CLASS_ADJACENT_POSITION;
        if (isset($ctx->classes[$lc])) {
            return;
        }

        $entry = new ClassEntry('Dom\\AdjacentPosition');
        $entry->isEnum = true;
        $entry->isInternal = true;
        $entry->backedType = 'string';
        foreach ([
            'BeforeBegin' => 'beforebegin',
            'AfterBegin' => 'afterbegin',
            'BeforeEnd' => 'beforeend',
            'AfterEnd' => 'afterend',
        ] as $name => $value) {
            $backing = new Variable();
            $backing->string($value);
            $caseLc = strtolower($name);
            $case = EnumCaseSupport::createCase($entry, $name, $backing);
            $entry->constants[$caseLc] = $case;
            $entry->enumCaseCanonicalNames[$caseLc] = $name;
            $entry->enumCases[] = [
                'name' => $name,
                'value' => $backing,
            ];
        }
        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);
        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    /** Share classic DOM* method handlers with living Dom\* types (#20418). */
    private static function copyMethods(?ClassEntry $from, ClassEntry $to): void
    {
        if (null === $from) {
            return;
        }
        foreach ($from->methods as $lc => $method) {
            if (isset($to->methods[$lc])) {
                continue;
            }
            $to->methods[$lc] = $method;
            if (isset($from->methodVisibility[$lc])) {
                $to->methodVisibility[$lc] = $from->methodVisibility[$lc];
            }
            if (isset($from->methodNames[$lc])) {
                $to->methodNames[$lc] = $from->methodNames[$lc];
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
            if (!isset($from->methods[$lc]) || isset($to->methods[$lc])) {
                continue;
            }
            $to->methods[$lc] = $from->methods[$lc];
            if (isset($from->methodVisibility[$lc])) {
                $to->methodVisibility[$lc] = $from->methodVisibility[$lc];
            }
            if (isset($from->methodNames[$lc])) {
                $to->methodNames[$lc] = $from->methodNames[$lc];
            }
        }
    }
}
