<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomHtmlDocumentCreateFromStringRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPLLVM\Value;

/**
 * LLVM lowering for Dom\HTMLDocument::createFromString() (#27300, #19580).
 *
 * php-src: ext/dom/html_document.c — Dom\HTMLDocument::createFromString
 *
 * Thin standalone AOT: compile-time string literals materialize a main-module
 * Dom\HTMLDocument with a pinned {@code body} slot (peer
 * {@see JitDomXmlDocumentCreateFromString}) so body→textContent does not touch
 * NestedJIT ObjectEntry layout.
 */
final class JitDomHtmlDocumentCreateFromString
{
    private const CLASS_DOCUMENT = 'Dom\\HTMLDocument';

    /** Body/documentElement use classic DOMElement layout (textContent slots; #27300). */
    private const CLASS_ELEMENT = 'DOMElement';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_html_create_from_string_cont');
        if (\count($args) < 1) {
            throw new \ArgumentCountError(
                'Dom\\HTMLDocument::createFromString() expects at least 1 argument, 0 given'
            );
        }

        $sourceArg = $args[0];
        $optionsArg = $args[1] ?? null;

        if (JITVariable::TYPE_NULL === $sourceArg->type || $sourceArg->isNullConstant) {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'Dom\\HTMLDocument::createFromString',
                0,
                'source'
            );
        }

        $sourceLit = JitStringBuiltinArg::compileTimeLiteral($sourceArg) ?? $sourceArg->compileTimeString;
        if (null !== $sourceLit && '' === $sourceLit) {
            TypeErrorRaise::emitValueError(
                $context,
                'Dom\\HTMLDocument::createFromString(): Argument #1 ($source) must not be empty'
            );
        }

        $optionsLit = 0;
        if (null !== $optionsArg && !NamedOptionalCallArgs::isOmittedOptional($optionsArg)) {
            $opt = $optionsArg->compileTimeLong;
            if (null === $opt) {
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
        $htmlStr = JitStringBuiltinArg::lower(
            $context,
            $sourceArg,
            'Dom\\HTMLDocument::createFromString',
            0,
            'source'
        );
        $options = $context->getTypeFromString('int64')->constInt(0, false);
        if (null !== $optionsArg && !NamedOptionalCallArgs::isOmittedOptional($optionsArg)) {
            $options = JitIntdiv::lowerIntBuiltinArg(
                $context,
                $optionsArg,
                'Dom\\HTMLDocument::createFromString()',
                2,
                'options'
            );
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        DomHtmlDocumentCreateFromStringRuntime::ensureLinked($context);

        $document = $context->builder->call(
            $context->lookupFunction(DomHtmlDocumentCreateFromStringRuntime::ABI_NAME),
            $htmlStr,
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
     * Allocate Dom\HTMLDocument + body (textContent) in the main module (#27300).
     */
    private static function tryMaterializeUserScript(Context $context, string $source): ?Value
    {
        $bodyText = DomParseSimpleHtmlJitHelper::bodyTextContentArgv($source);
        if (null === $bodyText) {
            return null;
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_html_cfs_us_materialize');
        // textContent fetch reads property slots when last load was pure user-script (#24121 / #27300).
        JitDomLoadXMLUserScript::markLastLoadPureUserScript();
        JitDomLoadXMLUserScript::rememberLivingDocumentClass(self::CLASS_DOCUMENT);

        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        $elemClassId = $objectType->lookup(self::CLASS_ELEMENT);
        self::ensureDocumentLayout($objectType, $docClassId);
        self::ensureElementLayout($objectType, $elemClassId);

        $document = $objectType->allocate($docClassId);
        $objectType->markObjectConstructed($document);

        $html = JitDomCreateElement::materializeElementWithTextContent(
            $context,
            'html',
            '',
            self::CLASS_ELEMENT
        );
        $body = JitDomCreateElement::materializeElementWithTextContent(
            $context,
            'body',
            $bodyText,
            self::CLASS_ELEMENT
        );

        $htmlJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $html
        );
        $bodyJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $body
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCUMENT_ELEMENT),
            $htmlJit,
            JITVariable::TYPE_OBJECT
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDomLiving::PROP_BODY),
            $bodyJit,
            JITVariable::TYPE_OBJECT
        );
        // Pin doctype before any PropertyFetch — undeclared slots get defineProperty'd
        // as uninitialized externals and return garbage ints / segfault (#28940).
        self::storeDoctypeProperty($context, $document, $source);
        DomUserScriptPinnedRootLlvm::pin($context, $body);

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
     * Initialize Dom\HTMLDocument::$doctype for user-script AOT (#28940).
     *
     * Fragments → null (php-src / #26924). Explicit {@code <!DOCTYPE name>} →
     * DocumentType stand-in with name/publicId/systemId slots (peer
     * {@see JitDomCreateDocumentType}).
     */
    private static function storeDoctypeProperty(
        Context $context,
        Value $document,
        string $source
    ): void {
        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCTYPE)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCTYPE, JITVariable::TYPE_VALUE);
        }

        $doctypeName = DomParseSimpleHtmlJitHelper::doctypeNameArgv($source);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCTYPE)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCTYPE, JITVariable::TYPE_VALUE);
        }
        if (null === $doctypeName) {
            $nullSlot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $nullSlot)
            );
            $nullVar = new JITVariable(
                $context,
                JITVariable::TYPE_VALUE,
                JITVariable::KIND_VARIABLE,
                $nullSlot
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCTYPE),
                $nullVar,
                JITVariable::TYPE_NULL
            );

            return;
        }

        $doctype = JitDomCreateDocumentType::materialize($context, $doctypeName, '', '');
        $doctypeJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $doctype
        );
        // VALUE slot + detached fetch in {@see JitDomDocumentDoctype} (#28940).
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCTYPE),
            $doctypeJit,
            JITVariable::TYPE_VALUE
        );
    }

    private static function ensureDocumentLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $docClassId
    ): void {
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
        if (!$objectType->hasProperty($docClassId, VmDomLiving::PROP_BODY)) {
            $objectType->defineProperty($docClassId, VmDomLiving::PROP_BODY, JITVariable::TYPE_OBJECT);
        }
        // doctype type chosen in storeDoctypeProperty (OBJECT when present, VALUE when null).
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
