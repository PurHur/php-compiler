<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Computed Dom\HTMLDocument / Dom\XMLDocument properties (php-src ext/dom/html_document.c; #6506, #19580).
 */
final class DomHtmlDocumentPropertySupport
{
    public static function isManagedProperty(ObjectEntry $object, string $name): bool
    {
        if (!VmDomLiving::isLivingDocument($object)) {
            return false;
        }
        $lc = strtolower($name);

        return VmDomLiving::PROP_BODY === $lc
            || VmDomLiving::PROP_HEAD === $lc
            || VmDomLiving::PROP_DOCUMENT_ELEMENT === $lc
            || VmDomLiving::PROP_TITLE === $lc;
    }

    public static function getProperty(ObjectEntry $object, string $name): Variable
    {
        VmDom::ensureDocument($object);
        $lc = strtolower($name);
        $var = new Variable();
        $var->objectPropertyOwner = $object;
        $var->objectPropertyName = $lc;
        if (VmDomLiving::PROP_DOCUMENT_ELEMENT === $lc) {
            return $object->getProperty(VmDom::PROP_DOCUMENT_ELEMENT);
        }
        if (VmDomLiving::PROP_BODY === $lc) {
            $body = VmDomLiving::htmlBodyElement($object);
            if (null === $body) {
                $var->null();
            } else {
                $var->object($body);
            }

            return $var;
        }
        if (VmDomLiving::PROP_HEAD === $lc) {
            $head = VmDomLiving::htmlHeadElement($object);
            if (null === $head) {
                $var->null();
            } else {
                $var->object($head);
            }

            return $var;
        }
        if (VmDomLiving::PROP_TITLE === $lc) {
            // title is HTMLDocument-only (php-src stub puts it on Document but XMLDocument ignores).
            if (VmDomLiving::CLASS_HTML_DOCUMENT !== strtolower($object->class->name)) {
                throw new \LogicException('DomHtmlDocumentPropertySupport::getProperty() title on non-HTMLDocument');
            }
            $var->string(VmDomLiving::htmlDocumentTitle($object));

            return $var;
        }

        throw new \LogicException('DomHtmlDocumentPropertySupport::getProperty() called with unmanaged name');
    }

    /**
     * @return bool true when the write was handled (caller should skip slot assign)
     */
    public static function tryAssign(
        ObjectEntry $owner,
        string $propName,
        Variable $value,
        Context $ctx
    ): bool {
        if (!self::isManagedProperty($owner, $propName)) {
            return false;
        }
        $lc = strtolower($propName);
        if (VmDomLiving::PROP_TITLE !== $lc) {
            // body/head/documentElement are computed; absorb ClassProperty init writes.
            return true;
        }
        if (VmDomLiving::CLASS_HTML_DOCUMENT !== strtolower($owner->class->name)) {
            return false;
        }
        $resolved = $value->resolveIndirect();
        // Absorb ClassProperty default init (null/bool) — title is a computed string.
        if (Variable::TYPE_NULL === $resolved->type || Variable::TYPE_BOOLEAN === $resolved->type) {
            return true;
        }
        if (Variable::TYPE_STRING !== $resolved->type) {
            throw new \TypeError(sprintf(
                'Cannot assign %s to property Dom\\HTMLDocument::$title of type string',
                VmDom::typeLabel($resolved)
            ));
        }
        VmDomLiving::setHtmlDocumentTitle($ctx, $owner, $resolved->toString());

        return true;
    }
}
