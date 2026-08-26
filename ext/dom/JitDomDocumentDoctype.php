<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for Document::$doctype (#28940 / #34887).
 *
 * Living / legacy documents expose doctype as a computed VM property
 * ({@see DomHtmlDocumentPropertySupport} / DomRegistry). Thin AOT has no
 * DomRegistry — undeclared PropertyFetch would {@code defineProperty} an
 * uninitialized external slot (garbage int / segfault). Prefer the slot
 * pinned by {@see JitDomHtmlDocumentCreateFromString}.
 *
 * Fetches are detached from {@code objectPropertySlot}: a direct slot fetch
 * makes {@code $doc->doctype === null} write null back into the property
 * (php-src doctype is read-only / computed).
 *
 * Pure loadXML cannot {@code defineProperty}+{@code propertyStore} doctype onto an
 * already-allocated DOMDocument — late layout shifts corrupt documentElement /
 * firstChild slots (saveXML SIGSEGV). Instead materialize a DocumentType
 * stand-in from {@see DomUserScriptDoctypeLlvm} at fetch time (#34887 leftover
 * of #34877). No {@code <!DOCTYPE>} → null.
 *
 * php-src: ext/dom/php_dom.c — dom_document_doctype_read
 * php-src: ext/dom/document.c — loadXML populates doc->intSubset / doctype
 */
final class JitDomDocumentDoctype
{
    private const PROP_DOCTYPE = 'doctype';

    public static function isDomDocumentDoctype(string $classLc, string $propLc): bool
    {
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        $propLc = strtolower($propLc);
        if ('doctype' !== $propLc) {
            return false;
        }

        return 'domdocument' === $classLc
            || 'dom\\document' === $classLc
            || 'dom\\htmldocument' === $classLc
            || 'dom\\xmldocument' === $classLc;
    }

    public static function fetch(Object_ $objectType, Value $obj, string $className): JITVariable
    {
        $context = $objectType->jitContext();
        if (!JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::detachedFetch($objectType, $obj, $className);
        }

        $docClass = JitDomLoadXMLUserScript::lastDocumentClass() ?? $className;
        $docClassId = $objectType->lookup($docClass);
        if (JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            $cfsSource = JitDomHtmlDocumentSaveHtml::lastCreateFromStringSource();
            if (null !== $cfsSource
                && null === DomParseSimpleHtmlJitHelper::doctypeNameArgv($cfsSource)
            ) {
                return self::boxNull($context);
            }
            // Pure loadXML: computed doctype from compile-time stamp (#34887).
            // Do not defineProperty/detachedFetch on the live document instance.
            if (null === $cfsSource) {
                return self::materializeFromLoadXmlStamp($context);
            }
        }
        if ($objectType->hasProperty($docClassId, self::PROP_DOCTYPE)
            || JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
        ) {
            if (!$objectType->hasProperty($docClassId, self::PROP_DOCTYPE)) {
                $objectType->defineProperty($docClassId, self::PROP_DOCTYPE, JITVariable::TYPE_VALUE);
            }

            return self::detachedFetch($objectType, $obj, $docClass);
        }

        return self::boxNull($context);
    }

    /**
     * Build DocumentType stand-in from {@see DomUserScriptDoctypeLlvm} (#34887).
     *
     * {@see JitDomCreateDocumentType::materialize} calls rememberCreate (clears
     * attached) — re-mark so saveXML keeps the #34877 prefix.
     */
    private static function materializeFromLoadXmlStamp(
        \PHPCompiler\JIT\Context $context
    ): JITVariable {
        if (!DomUserScriptDoctypeLlvm::isAttached()) {
            return self::boxNull($context);
        }
        $name = DomUserScriptDoctypeLlvm::qualifiedName();
        if (null === $name || '' === $name) {
            return self::boxNull($context);
        }
        $doctype = JitDomCreateDocumentType::materialize(
            $context,
            $name,
            DomUserScriptDoctypeLlvm::publicId(),
            DomUserScriptDoctypeLlvm::systemId()
        );
        DomUserScriptDoctypeLlvm::markAttached();

        return new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $doctype
        );
    }

    /**
     * Copy the property into a fresh __value__ with no objectPropertySlot (#28940).
     */
    private static function detachedFetch(
        Object_ $objectType,
        Value $obj,
        string $docClass
    ): JITVariable {
        $context = $objectType->jitContext();
        $docClassId = $objectType->lookup($docClass);
        $fetched = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            $docClass,
            self::PROP_DOCTYPE,
            $docClassId
        );
        $copy = JitValueBox::alloc($context);
        ObjectInstancePropertyLlvm::boxFetchedPropertyIntoValue(
            $objectType,
            $copy,
            $fetched,
            $fetched->objectPropertyType ?? $fetched->type
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $copy
        );
    }

    private static function boxNull(\PHPCompiler\JIT\Context $context): JITVariable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $slot
        );
    }
}
