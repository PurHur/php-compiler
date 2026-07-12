<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Computed Dom\HTMLDocument / Dom\XMLDocument properties (php-src ext/dom/html_document.c; #6506).
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
            || VmDomLiving::PROP_DOCUMENT_ELEMENT === $lc;
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

        throw new \LogicException('DomHtmlDocumentPropertySupport::getProperty() called with unmanaged name');
    }
}
