<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument computed meta / baseURI properties
 * (#34894 leftover of #34887; #34904 baseURI).
 *
 * Option bools (formatOutput, …), xmlVersion / xmlStandalone, encoding, and
 * documentURI use allocate()+DomDocumentConstruct seeds so writes stick
 * (#34908 / #34916 / #34919 / #34925) — not hardcoded fetch.
 * xmlEncoding / actualEncoding / baseURI read the encoding / documentURI slots
 * (php-src aliases; write-rejected in Object_.php).
 *
 * php-src: ext/dom/php_dom.c — dom_document_*_read; ext/dom/node.c — dom_node_base_uri_read;
 * ext/dom/document.c
 */
final class JitDomDocumentMetaProps
{
    public static function isDomDocumentMetaProp(string $classLc, string $propLc): bool
    {
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        $propLc = strtolower($propLc);
        if ('domdocument' !== $classLc
            && 'dom\\document' !== $classLc
            && 'dom\\xmldocument' !== $classLc
        ) {
            return false;
        }

        return 'implementation' === $propLc
            || 'xmlencoding' === $propLc
            || 'actualencoding' === $propLc
            || 'config' === $propLc
            // DOMNode::$baseURI — read-only alias of documentURI (#34925 / #34904).
            || 'baseuri' === $propLc;
    }

    public static function fetch(Object_ $objectType, Value $obj, string $className, string $propName): JITVariable
    {
        $context = $objectType->jitContext();
        $propLc = strtolower($propName);

        if ('config' === $propLc) {
            return self::boxNull($context);
        }
        if ('implementation' === $propLc) {
            return self::boxImplementation($context);
        }
        // Read-only aliases of $encoding (php-src document.c; #34919).
        if ('xmlencoding' === $propLc || 'actualencoding' === $propLc) {
            $classId = $objectType->lookup('DOMDocument');

            return \PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $obj,
                'DOMDocument',
                VmDom::PROP_ENCODING,
                $classId,
                false
            );
        }
        // Read-only alias of $documentURI (php-src node.c base_uri_read; #34925).
        if ('baseuri' === $propLc) {
            $classId = $objectType->lookup('DOMDocument');

            return \PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $obj,
                'DOMDocument',
                VmDom::PROP_DOCUMENT_URI,
                $classId,
                false
            );
        }

        return self::boxNull($context);
    }

    private static function boxImplementation(\PHPCompiler\JIT\Context $context): JITVariable
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMImplementation');
        $impl = $objectType->allocate($classId);
        $objectType->markObjectConstructed($impl);

        return new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $impl
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
