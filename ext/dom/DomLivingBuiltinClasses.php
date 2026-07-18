<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/** Register PHP 8.4 Dom\ living-standard classes (php-src ext/dom/php_dom.stub.php; #6506). */
final class DomLivingBuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
            return;
        }
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
        $ctx->classes[VmDomLiving::CLASS_ELEMENT] = $element;

        $htmlElement = new ClassEntry('Dom\\HTMLElement');
        $htmlElement->isInternal = true;
        $htmlElement->parentLc = VmDomLiving::CLASS_ELEMENT;
        $ctx->classes[VmDomLiving::CLASS_HTML_ELEMENT] = $htmlElement;

        $document = new ClassEntry('Dom\\Document');
        $document->isInternal = true;
        $document->properties[] = new ClassProperty(VmDomLiving::PROP_DOCUMENT_ELEMENT, $nullProto, $objProto);
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
        $ctx->classes[VmDomLiving::CLASS_XML_DOCUMENT] = $xmlDocument;
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
}
