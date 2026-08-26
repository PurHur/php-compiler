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
 * Thin AOT has no DomRegistry — undeclared PropertyFetch after loadXML late-defines an
 * uninitialized slot (SIGSEGV). Props are pinned in {@see Object_::allocate} layout;
 * fetches return computed values (peer {@see JitDomDocumentDoctype} / #28940).
 *
 * Libxml option bools (formatOutput, preserveWhiteSpace, …) are ordinary ClassProperty
 * slots seeded in {@see \PHPCompiler\JIT\Call\DomDocumentConstruct} — not computed here —
 * so PropertyAssign sticks (#34908 leftover of #34899).
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
            || 'documenturi' === $propLc
            || 'xmlencoding' === $propLc
            || 'actualencoding' === $propLc
            || 'encoding' === $propLc
            || 'xmlversion' === $propLc
            || 'version' === $propLc
            || 'xmlstandalone' === $propLc
            || 'standalone' === $propLc
            || 'config' === $propLc
            // DOMNode::$baseURI — same documentURI cwd stamp after loadXML (#34904).
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
        if ('xmlversion' === $propLc || 'version' === $propLc) {
            return self::boxString($context, '1.0');
        }
        if ('xmlstandalone' === $propLc || 'standalone' === $propLc) {
            return self::boxBool($context, false);
        }
        if ('xmlencoding' === $propLc || 'actualencoding' === $propLc || 'encoding' === $propLc) {
            $enc = self::encodingFromLoadXmlStamp();
            if (null === $enc) {
                return self::boxNull($context);
            }

            return self::boxString($context, $enc);
        }
        if ('documenturi' === $propLc || 'baseuri' === $propLc) {
            // loadXML(string) sets documentURI/baseURI to the CWD in php-src; empty doc stays null.
            // php-src: dom_node_base_uri_read → xmlNodeGetBase on the document (#34904).
            if (JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
                $cwd = \getcwd();
                if (false !== $cwd && '' !== $cwd) {
                    // Trailing slash matches Zend cwd documentURI in the pinned container.
                    if ('/' !== substr($cwd, -1)) {
                        $cwd .= '/';
                    }

                    return self::boxString($context, $cwd);
                }
            }

            return self::boxNull($context);
        }

        return self::boxNull($context);
    }

    private static function encodingFromLoadXmlStamp(): ?string
    {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXmlSource()
            ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === $xml) {
            return null;
        }
        if (1 !== preg_match('/<\?xml[^>]*encoding\s*=\s*["\']([^"\']+)["\']/i', $xml, $m)) {
            return null;
        }

        return (string) $m[1];
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

    private static function boxString(\PHPCompiler\JIT\Context $context, string $value): JITVariable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $context->builder->load($context->constantStringFromString($value))
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $slot
        );
    }

    private static function boxBool(\PHPCompiler\JIT\Context $context, bool $value): JITVariable
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->zext($i1->constInt($value ? 1 : 0, false), $i32)
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $slot
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
