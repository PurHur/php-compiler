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
 * User-script AOT: dom_import_simplexml() / Dom\import_simplexml() (#34413, #34481).
 *
 * php-src ext/dom/node.c PHP_FUNCTION(dom_import_simplexml) and
 * ext/dom/php_dom.c Dom_import_simplexml (PHP_LIBXML_CLASS_MODERN) — wraps the
 * SimpleXML element as DOMElement / Dom\Element under a fresh document.
 * NestedJIT of the live peer bridge is unsafe for host-tracked SXE objects;
 * materialize like loadXML / createFromString.
 */
final class JitDomImportSimpleXmlUserScript
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_ELEMENT = 'DOMElement';

    private const CLASS_LIVING_DOCUMENT = 'Dom\\XMLDocument';

    private const CLASS_LIVING_ELEMENT = 'Dom\\Element';

    /** @var array<string, string>|null */
    private static ?array $pendingImportAttributes = null;

    /** Host SXE compile-time token passed to dom_import_simplexml() (#20137). */
    private static ?string $pendingImportHostSxeToken = null;

    /** Last import host token — method-call receivers may drop compile-time stamps (#20137). */
    private static ?string $lastHostImportToken = null;

    public static function tryImport(Context $context, JITVariable ...$args): ?Value
    {
        return self::tryImportInternal($context, false, ...$args);
    }

    /** Living Dom\import_simplexml() — Dom\XMLDocument + Dom\Element (#34481 / #20711). */
    public static function tryImportModern(Context $context, JITVariable ...$args): ?Value
    {
        return self::tryImportInternal($context, true, ...$args);
    }

    private static function tryImportInternal(Context $context, bool $modern, JITVariable ...$args): ?Value
    {
        if (!UserScriptAotEnv::isActive() || 1 !== \count($args)) {
            return null;
        }
        $host = JitSimpleXmlUserScript::compileTimeTree($args[0]);
        if (null === $host) {
            return null;
        }
        $xml = $host->asXML();
        if (false === $xml || '' === $xml) {
            return null;
        }
        $forParse = ltrim(VmDom::stripLeadingUtf8Bom($xml));
        $forParse = preg_replace('/^\s*<\?xml[^?]*\?>\s*/i', '', $forParse) ?? $forParse;
        $forParse = ltrim($forParse);
        if ('' === $forParse || '<' !== $forParse[0]) {
            return null;
        }
        if (1 !== preg_match('/<([a-zA-Z_][\w:.-]*)/', $forParse)) {
            return null;
        }

        $docClass = $modern ? self::CLASS_LIVING_DOCUMENT : self::CLASS_DOCUMENT;
        $elemClass = $modern ? self::CLASS_LIVING_ELEMENT : self::CLASS_ELEMENT;

        BasicBlockHelper::ensureOpenInsertBlock(
            $context,
            $modern ? 'dom_ns_import_simplexml_us' : 'dom_import_simplexml_us'
        );
        JitDomLoadXMLUserScript::rememberCompileTimeXml($forParse, $docClass);
        JitDomLoadXMLUserScript::markLastLoadPureUserScript();
        if ($modern) {
            JitDomLoadXMLUserScript::rememberLivingDocumentClass($docClass);
            JitDomAttributeNodeNS::ensureLivingAttrMethods($context);
        }

        $objectType = $context->type->object;
        $docClassId = $objectType->lookup($docClass);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
        $document = $objectType->allocate($docClassId);
        $objectType->markObjectConstructed($document);
        // Unset NATIVE_LONG nodeType → empty/SIGSEGV on $doc->nodeType (#35177 peer #35173).
        JitDomCreateElement::storeNodeType(
            $context,
            $document,
            $docClass,
            DomConstants::XML_DOCUMENT_NODE
        );

        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($forParse);
        $text = DomParseSimpleXmlJitHelper::rootTextContentArgv($forParse);
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($forParse);
        $attrs = DomParseSimpleXmlJitHelper::rootAttributesArgv($forParse);
        $element = JitDomCreateElement::materializeElementWithTextContent(
            $context,
            $tag,
            $text,
            $elemClass
        );
        JitDomCreateElement::storeUserScriptInnerXml($context, $element, $inner, $elemClass);
        $rootMarkup = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($forParse);
        if (null !== $rootMarkup && '' !== $rootMarkup['attrs']) {
            JitDomCreateElement::storeUserScriptXmlnsAttr($context, $element, $rootMarkup['attrs'], $elemClass);
        }
        JitDomCreateElement::storeAttributesPresence($context, $element, $attrs, $elemClass);
        JitDomGetNodePath::storeOn($context, $element, $elemClass, '/'.$tag);
        JitDomDocumentElement::syncChildrenFromXmlPublic(
            $context,
            $element,
            $forParse,
            '/'.$tag,
            $document
        );

        $elemJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $element
        );
        $docJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $document
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, $docClass, VmDom::PROP_DOCUMENT_ELEMENT),
            $elemJit,
            JITVariable::TYPE_OBJECT
        );

        $elementClassId = $objectType->lookup($elemClass);
        foreach ([VmDom::PROP_PARENT_NODE, VmDom::PROP_OWNER_DOCUMENT] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
            $objectType->propertyStore(
                $objectType->propertySlotFor($element, $elemClass, $prop),
                $docJit,
                JITVariable::TYPE_VALUE
            );
        }

        DomUserScriptPinnedRootLlvm::pin($context, $element);

        $attrMap = [];
        foreach ($attrs as $pair) {
            $attrMap[$pair['qname']] = $pair['value'];
        }
        // After child sync (which materializes siblings). ARG_SEND temps drop
        // compileTimeDomAttributes; stamp lastFetched for getAttribute (#34413).
        JitDomNodeChildProperty::$lastFetchedAttributes = $attrMap;
        self::$pendingImportAttributes = $attrMap;
        self::$pendingImportHostSxeToken = JitSimpleXmlUserScript::ensureCompileTimeToken($args[0], $host);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $element
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /** Stamp import result Variable so getAttribute survives nodeName fetch (#34413). */
    public static function applyPendingImportAssign(JITVariable $result): bool
    {
        if (null === self::$pendingImportAttributes) {
            return false;
        }
        $result->compileTimeDomAttributes = self::$pendingImportAttributes;
        JitDomNodeChildProperty::$lastFetchedAttributes = self::$pendingImportAttributes;
        self::$pendingImportAttributes = null;
        $hostToken = self::$pendingImportHostSxeToken;
        self::$pendingImportHostSxeToken = null;
        if (null !== $hostToken && '' !== $hostToken) {
            $result->compileTimeDomImportHostSxeToken = $hostToken;
            self::$lastHostImportToken = $hostToken;
        }

        return true;
    }

    public static function lastHostImportToken(): ?string
    {
        return self::$lastHostImportToken;
    }

    /** Mirror DOM textContent write onto the linked host SimpleXMLElement (#20137). */
    public static function syncHostSimpleXmlText(Context $context, ?string $hostToken, string $text): void
    {
        if (null === $hostToken || '' === $hostToken) {
            return;
        }
        JitSimpleXmlUserScript::syncHostTreeTextContent($context, $hostToken, $text);
    }

    /** Mirror DOM setAttribute onto the linked host SimpleXMLElement (#20137). */
    public static function syncHostSimpleXmlAttribute(
        Context $context,
        ?string $hostToken,
        string $name,
        string $value
    ): void {
        if (null === $hostToken || '' === $hostToken || '' === $name) {
            return;
        }
        JitSimpleXmlUserScript::syncHostTreeAttribute($context, $hostToken, $name, $value);
    }
}
