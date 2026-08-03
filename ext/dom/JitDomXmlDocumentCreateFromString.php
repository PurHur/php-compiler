<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomXmlDocumentCreateFromStringRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPLLVM\Value;

/**
 * LLVM lowering for Dom\XMLDocument::createFromString() (#27108, #19581).
 *
 * php-src: ext/dom/xml_document.c — Dom\XMLDocument::createFromString
 *
 * Thin standalone AOT: compile-time string literals materialize a main-module
 * Dom\XMLDocument (peer {@see JitDomLoadXMLUserScript}) so get_class /
 * documentElement / Attr::rename do not touch NestedJIT ObjectEntry layout.
 */
final class JitDomXmlDocumentCreateFromString
{
    private const CLASS_DOCUMENT = 'Dom\\XMLDocument';

    /** Document element uses classic DOMElement layout (tagName/nodeName slots; #27108). */
    private const CLASS_ELEMENT = 'DOMElement';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xml_create_from_string_cont');
        if (\count($args) < 1) {
            throw new \ArgumentCountError(
                'Dom\\XMLDocument::createFromString() expects at least 1 argument, 0 given'
            );
        }

        $sourceArg = $args[0];
        $optionsArg = $args[1] ?? null;

        if (JITVariable::TYPE_NULL === $sourceArg->type || $sourceArg->isNullConstant) {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'Dom\\XMLDocument::createFromString',
                0,
                'source'
            );
        }

        $sourceLit = JitStringBuiltinArg::compileTimeLiteral($sourceArg) ?? $sourceArg->compileTimeString;
        if (null !== $sourceLit && '' === $sourceLit) {
            TypeErrorRaise::emitValueError(
                $context,
                'Dom\\XMLDocument::createFromString(): Argument #1 ($source) must not be empty'
            );
        }

        $optionsLit = 0;
        if (null !== $optionsArg && !NamedOptionalCallArgs::isOmittedOptional($optionsArg)) {
            $opt = $optionsArg->compileTimeLong;
            if (null === $opt) {
                // Non-literal options → NestedJIT parse path.
                return self::invokeViaHelper($context, $sourceArg, $optionsArg);
            }
            $optionsLit = (int) $opt;
        }

        if (0 === $optionsLit && null !== $sourceLit && '' !== $sourceLit
            && JitDomDocumentMethodKernel::shouldUse($context)
            && CompilerVersion::supportsDomLivingStandardNamespace()
        ) {
            $us = self::tryMaterializeUserScript($context, $sourceLit);
            if (null !== $us) {
                return $us;
            }
        }

        return self::invokeViaHelper($context, $sourceArg, $optionsArg);
    }

    private static function invokeViaHelper(
        Context $context,
        JITVariable $sourceArg,
        ?JITVariable $optionsArg
    ): Value {
        $xmlStr = JitStringBuiltinArg::lower(
            $context,
            $sourceArg,
            'Dom\\XMLDocument::createFromString',
            0,
            'source'
        );
        $options = $context->getTypeFromString('int64')->constInt(0, false);
        if (null !== $optionsArg && !NamedOptionalCallArgs::isOmittedOptional($optionsArg)) {
            $options = JitIntdiv::lowerIntBuiltinArg(
                $context,
                $optionsArg,
                'Dom\\XMLDocument::createFromString()',
                2,
                'options'
            );
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        DomXmlDocumentCreateFromStringRuntime::ensureLinked($context);

        $document = $context->builder->call(
            $context->lookupFunction(DomXmlDocumentCreateFromStringRuntime::ABI_NAME),
            $xmlStr,
            $options
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $document
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    /**
     * Allocate Dom\XMLDocument + documentElement + Attr cache in the main module (#27108).
     */
    private static function tryMaterializeUserScript(Context $context, string $source): ?Value
    {
        $lit = VmDom::stripLeadingUtf8Bom($source);
        $forParse = ltrim($lit);
        if ('' === $forParse || '<' !== $forParse[0]) {
            return null;
        }
        if (1 !== preg_match('/<([a-zA-Z_][\w:.-]*)/', $forParse)) {
            return null;
        }
        // Inter-element blanks need full DomRegistry parse (peer loadXML).
        if (JitDomLoadXMLUserScript::xmlContainsInterElementBlankText($forParse)) {
            return null;
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xml_cfs_us_materialize');
        JitDomLoadXMLUserScript::rememberCompileTimeXml($forParse, self::CLASS_DOCUMENT);
        JitDomLoadXMLUserScript::markLastLoadPureUserScript();
        JitDomLoadXMLUserScript::rememberLivingDocumentClass(self::CLASS_DOCUMENT);

        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        $elemClassId = $objectType->lookup(self::CLASS_ELEMENT);
        self::ensureDocumentLayout($objectType, $docClassId);
        self::ensureElementLayout($objectType, $elemClassId);
        JitDomAttributeNodeNS::ensureLivingAttrMethods($context);

        $document = $objectType->allocate($docClassId);
        $objectType->markObjectConstructed($document);

        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($forParse);
        $text = DomParseSimpleXmlJitHelper::rootTextContentArgv($forParse);
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($forParse);
        $element = JitDomCreateElement::materializeElementWithTextContent(
            $context,
            $tag,
            $text,
            self::CLASS_ELEMENT
        );
        JitDomCreateElement::storeUserScriptInnerXml($context, $element, $inner, self::CLASS_ELEMENT);
        JitDomDocumentElement::syncChildrenFromXmlPublic($context, $element, $forParse);

        $elemJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $element
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCUMENT_ELEMENT),
            $elemJit,
            JITVariable::TYPE_OBJECT
        );
        DomUserScriptPinnedRootLlvm::pin($context, $element);

        foreach (DomParseSimpleXmlJitHelper::rootAttributesArgv($forParse) as $attrPair) {
            $qname = $attrPair['qname'];
            $value = $attrPair['value'];
            $pos = strpos($qname, ':');
            $local = false === $pos ? $qname : substr($qname, $pos + 1);
            $attr = JitDomAttributeNodeNS::materializeAttrFromLiterals(
                $context,
                '',
                $qname,
                $value,
                JitDomAttributeNodeNS::CLASS_LIVING_ATTR,
                true
            );
            DomUserScriptAttributeCacheLlvm::storeLiteral($context, '', $local, $attr, $value);
            // Also key by qname when unprefixed (local === qname).
            if ($local !== $qname) {
                DomUserScriptAttributeCacheLlvm::storeLiteral($context, '', $qname, $attr, $value);
            }
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $document
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function ensureDocumentLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $docClassId
    ): void {
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
    }

    private static function ensureElementLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $elemClassId
    ): void {
        foreach (['nodeName', 'tagName', 'textContent', 'nodeValue', VmDom::PROP_USER_SCRIPT_INNER_XML] as $prop) {
            if (!$objectType->hasProperty($elemClassId, $prop)) {
                $objectType->defineProperty($elemClassId, $prop, JITVariable::TYPE_STRING);
            }
        }
        if (!$objectType->hasProperty($elemClassId, 'attributes')) {
            $objectType->defineProperty($elemClassId, 'attributes', JITVariable::TYPE_VALUE);
        }
    }
}
