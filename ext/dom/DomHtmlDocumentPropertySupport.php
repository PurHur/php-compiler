<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Computed Dom\HTMLDocument / Dom\XMLDocument properties (php-src ext/dom/html_document.c; #6506, #19580, #20540, #20898).
 *
 * Living Document props (URL/documentURI/characterSet/doctype/implementation) mirror
 * php-src `dom_abstract_base_document_prop_handlers` in ext/dom/php_dom.c.
 */
final class DomHtmlDocumentPropertySupport
{
    public const PROP_URL = 'url';

    public const PROP_CHARACTER_SET = 'characterset';

    public const PROP_CHARSET = 'charset';

    public const PROP_INPUT_ENCODING = 'inputencoding';

    public static function isManagedProperty(ObjectEntry $object, string $name): bool
    {
        if (!VmDomLiving::isLivingDocument($object)) {
            return false;
        }
        $lc = strtolower($name);

        if (VmDomLiving::PROP_BODY === $lc
            || VmDomLiving::PROP_HEAD === $lc
            || VmDomLiving::PROP_DOCUMENT_ELEMENT === $lc
            || self::PROP_URL === $lc
            || strtolower(VmDom::PROP_DOCUMENT_URI) === $lc
            || self::PROP_CHARACTER_SET === $lc
            || self::PROP_CHARSET === $lc
            || self::PROP_INPUT_ENCODING === $lc
            || strtolower(VmDom::PROP_DOCTYPE) === $lc
            || strtolower(VmDom::PROP_IMPLEMENTATION) === $lc
        ) {
            return true;
        }
        // title is HTMLDocument-only (php-src stub; XMLDocument has no title getter).
        if (VmDomLiving::PROP_TITLE === $lc
            && VmDomLiving::CLASS_HTML_DOCUMENT === strtolower($object->class->name)
        ) {
            return true;
        }
        // XMLDocument-only xml* props (php-src php_dom.stub.php; #20898).
        // formatOutput stays a ClassProperty slot (same as legacy DOMDocument).
        if (VmDomLiving::CLASS_XML_DOCUMENT === strtolower($object->class->name)) {
            return strtolower(VmDom::PROP_XML_VERSION) === $lc
                || strtolower(VmDom::PROP_XML_STANDALONE) === $lc
                || strtolower(VmDom::PROP_XML_ENCODING) === $lc;
        }

        return false;
    }

    /**
     * isset($doc->body|/title|/…) — computed value, not the null ClassProperty slot (#20540).
     *
     * @return bool|null null when this support does not own the property
     */
    public static function propertyIsSet(ObjectEntry $object, string $name): ?bool
    {
        if (!self::isManagedProperty($object, $name)) {
            return null;
        }
        $var = self::getProperty($object, $name)->resolveIndirect();

        return !$var->isUndefined() && Variable::TYPE_NULL !== $var->type;
    }

    /**
     * empty($doc->body|/title|/…) — Zend has_property then value truthiness (#20540).
     *
     * @return bool|null null when this support does not own the property
     */
    public static function propertyIsEmpty(ObjectEntry $object, string $name): ?bool
    {
        if (!self::isManagedProperty($object, $name)) {
            return null;
        }
        $var = self::getProperty($object, $name);

        return !\PHPCompiler\ext\standard\boolval::isTruthy($var);
    }

    public static function getProperty(ObjectEntry $object, string $name): Variable
    {
        VmDom::ensureDocument($object);
        $lc = strtolower($name);
        $var = new Variable();
        $var->objectPropertyOwner = $object;
        $var->objectPropertyName = $lc;
        $state = DomRegistry::state($object);

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
        if (self::PROP_URL === $lc || strtolower(VmDom::PROP_DOCUMENT_URI) === $lc) {
            // php-src dom_document_document_uri_read — about:blank when follow_spec and URL unset (#20898).
            $uri = $state->documentUri;
            $var->string(null === $uri || '' === $uri ? 'about:blank' : $uri);

            return $var;
        }
        if (self::PROP_CHARACTER_SET === $lc
            || self::PROP_CHARSET === $lc
            || self::PROP_INPUT_ENCODING === $lc
        ) {
            // php-src dom_document_encoding_read / characterSet aliases (#20898).
            $encoding = $state->encoding;
            if (null === $encoding || '' === $encoding) {
                $encoding = 'UTF-8';
            }
            $var->string($encoding);

            return $var;
        }
        if (strtolower(VmDom::PROP_DOCTYPE) === $lc) {
            if (null === $state->doctypeId) {
                $var->null();
            } else {
                $doctype = DomRegistry::entry($state->doctypeId);
                if (null !== $doctype) {
                    $var->object($doctype);
                } else {
                    $var->null();
                }
            }

            return $var;
        }
        if (strtolower(VmDom::PROP_IMPLEMENTATION) === $lc) {
            $var->object(VmDomLiving::implementationSingleton());

            return $var;
        }
        if (VmDomLiving::CLASS_XML_DOCUMENT === strtolower($object->class->name)) {
            if (strtolower(VmDom::PROP_XML_VERSION) === $lc) {
                $var->string($state->xmlVersion);

                return $var;
            }
            if (strtolower(VmDom::PROP_XML_STANDALONE) === $lc) {
                $var->bool($state->xmlStandalone);

                return $var;
            }
            if (strtolower(VmDom::PROP_XML_ENCODING) === $lc) {
                if (null === $state->encoding) {
                    $var->null();
                } else {
                    $var->string($state->encoding);
                }

                return $var;
            }
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
        if (VmDomLiving::PROP_TITLE !== $lc
            && self::PROP_URL !== $lc
            && strtolower(VmDom::PROP_DOCUMENT_URI) !== $lc
            && self::PROP_CHARACTER_SET !== $lc
            && self::PROP_CHARSET !== $lc
            && self::PROP_INPUT_ENCODING !== $lc
            && strtolower(VmDom::PROP_XML_VERSION) !== $lc
            && strtolower(VmDom::PROP_XML_STANDALONE) !== $lc
        ) {
            // body/head/documentElement/doctype/implementation/xmlEncoding are computed; absorb init writes.
            return true;
        }
        $resolved = $value->resolveIndirect();
        $state = DomRegistry::state($owner);

        if (VmDomLiving::PROP_TITLE === $lc) {
            if (VmDomLiving::CLASS_HTML_DOCUMENT !== strtolower($owner->class->name)) {
                return false;
            }
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

        if (self::PROP_URL === $lc || strtolower(VmDom::PROP_DOCUMENT_URI) === $lc) {
            $className = $owner->class->name;
            if (Variable::TYPE_NULL === $resolved->type) {
                $state->documentUri = null;
            } elseif (Variable::TYPE_STRING === $resolved->type) {
                $state->documentUri = $resolved->toString();
            } else {
                throw new \TypeError(sprintf(
                    'Cannot assign %s to property %s::$%s of type string',
                    VmDom::typeLabel($resolved),
                    $className,
                    self::PROP_URL === $lc ? 'URL' : 'documentURI'
                ));
            }

            return true;
        }

        if (self::PROP_CHARACTER_SET === $lc
            || self::PROP_CHARSET === $lc
            || self::PROP_INPUT_ENCODING === $lc
        ) {
            $propLabel = self::PROP_CHARACTER_SET === $lc ? 'characterSet'
                : (self::PROP_CHARSET === $lc ? 'charset' : 'inputEncoding');
            if (Variable::TYPE_NULL === $resolved->type || Variable::TYPE_BOOLEAN === $resolved->type) {
                // Absorb ClassProperty init.
                return true;
            }
            if (Variable::TYPE_STRING !== $resolved->type) {
                throw new \TypeError(sprintf(
                    'Cannot assign %s to property %s::$%s of type string',
                    VmDom::typeLabel($resolved),
                    $owner->class->name,
                    $propLabel
                ));
            }
            $encoding = $resolved->toString();
            if ('' === $encoding) {
                throw new \ValueError(sprintf(
                    'Cannot assign empty string to property %s::$%s of type string',
                    $owner->class->name,
                    $propLabel
                ));
            }
            $state->encoding = $encoding;

            return true;
        }

        if (VmDomLiving::CLASS_XML_DOCUMENT !== strtolower($owner->class->name)) {
            return true;
        }

        if (strtolower(VmDom::PROP_XML_VERSION) === $lc) {
            if (Variable::TYPE_NULL === $resolved->type || Variable::TYPE_BOOLEAN === $resolved->type) {
                return true;
            }
            if (Variable::TYPE_STRING !== $resolved->type) {
                throw new \TypeError(sprintf(
                    'Cannot assign %s to property Dom\\XMLDocument::$xmlVersion of type string',
                    VmDom::typeLabel($resolved)
                ));
            }
            $state->xmlVersion = $resolved->toString();

            return true;
        }
        if (strtolower(VmDom::PROP_XML_STANDALONE) === $lc) {
            if (Variable::TYPE_NULL === $resolved->type) {
                return true;
            }
            if (Variable::TYPE_BOOLEAN !== $resolved->type) {
                throw new \TypeError(sprintf(
                    'Cannot assign %s to property Dom\\XMLDocument::$xmlStandalone of type bool',
                    VmDom::typeLabel($resolved)
                ));
            }
            $state->xmlStandalone = $resolved->toBool();

            return true;
        }

        return true;
    }
}
