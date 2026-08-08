<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for Document::$doctype (#28940).
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
 * php-src: ext/dom/php_dom.c — dom_document_doctype_read
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
