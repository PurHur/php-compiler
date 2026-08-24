<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\simplexml\JitSimpleXmlUserScript;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script AOT: dom_import_simplexml() from a tracked host SimpleXMLElement (#34413).
 *
 * php-src: ext/dom/node.c — PHP_FUNCTION(dom_import_simplexml)
 *
 * NestedJIT of {@see VmDomSimpleXmlBridge} is not available on the thin standalone
 * path. Compile-time host SXE ({@see JitSimpleXmlUserScript}) is serialized via
 * asXML() and materialized as DOMElement; open-tag attrs are stamped for
 * getAttribute / nodeName so AOT matches Zend.
 */
final class JitDomImportSimpleXmlUserScript
{
    private const CLASS_ELEMENT = 'DOMElement';

    /**
     * Pending compile-time stamps after tryImport — ARG_SEND drops Attr cache reads (#34413).
     *
     * @var null|array{tag: string, attrs: array<string, string>, inner: string, xml: string}
     */
    private static ?array $pendingImportAssign = null;

    /**
     * Attach tag/attrs onto the Call result Variable so getAttribute sees open-tag values.
     *
     * @return bool true when a pending import was applied
     */
    public static function applyPendingImportAssign(JITVariable $result): bool
    {
        $pending = self::$pendingImportAssign;
        self::$pendingImportAssign = null;
        if (null === $pending) {
            return false;
        }
        $result->compileTimeDomTagName = $pending['tag'];
        $result->compileTimeDomInnerXml = $pending['inner'];
        $result->compileTimeDomLoadXml = $pending['xml'];
        $result->classUserType = self::CLASS_ELEMENT;
        if ([] !== $pending['attrs']) {
            $result->compileTimeDomAttributes = $pending['attrs'];
            JitDomNodeChildProperty::$lastFetchedAttributes = $pending['attrs'];
        }

        return true;
    }

    public static function tryImport(Context $context, JITVariable ...$args): ?Value
    {
        if (!UserScriptAotEnv::isActive() || \count($args) < 1 || !\extension_loaded('simplexml')) {
            return null;
        }

        $tree = JitSimpleXmlUserScript::hostTreeForForeach($args[0]);
        if (null === $tree) {
            return null;
        }

        $xml = $tree->asXML();
        if (false === $xml || '' === $xml) {
            return null;
        }
        $forParse = ltrim((string) preg_replace('/^\\s*<\\?xml[^?]*\\?>\\s*/i', '', $xml));
        if ('' === $forParse || '<' !== $forParse[0]) {
            return null;
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_import_simplexml_us');
        JitDomLoadXMLUserScript::rememberCompileTimeXml($forParse);
        JitDomLoadXMLUserScript::markLastLoadPureUserScript();

        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($forParse);
        $text = DomParseSimpleXmlJitHelper::rootTextContentArgv($forParse);
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($forParse);
        $attrs = DomParseSimpleXmlJitHelper::rootAttributesArgv($forParse);

        $element = JitDomCreateElement::materializeElementWithTextContent(
            $context,
            $tag,
            $text,
            self::CLASS_ELEMENT
        );
        JitDomCreateElement::storeUserScriptInnerXml($context, $element, $inner, self::CLASS_ELEMENT);
        $rootMarkup = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($forParse);
        if (null !== $rootMarkup && '' !== $rootMarkup['attrs']) {
            JitDomCreateElement::storeUserScriptXmlnsAttr(
                $context,
                $element,
                $rootMarkup['attrs'],
                self::CLASS_ELEMENT
            );
        }
        // Do not seed DomUserScriptAttributeCacheLlvm Attr objects here: getAttribute
        // prefers live Attr::$value, and ARG_SEND temps miss those slots (#34413).
        // rememberCompileTimeXml + compileTimeDomAttributes drive getAttribute instead.
        JitDomDocumentElement::syncChildrenFromXmlPublic($context, $element, $forParse, '/'.$tag);
        DomUserScriptPinnedRootLlvm::pin($context, $element);

        $attrMap = [];
        foreach ($attrs as $pair) {
            $attrMap[$pair['qname']] = $pair['value'];
        }
        self::$pendingImportAssign = [
            'tag' => $tag,
            'attrs' => $attrMap,
            'inner' => $inner,
            'xml' => $forParse,
        ];
        JitDomNodeChildProperty::$lastFetchedAttributes = [] === $attrMap ? null : $attrMap;

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $element
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
