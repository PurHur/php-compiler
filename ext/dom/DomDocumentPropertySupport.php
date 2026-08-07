<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Computed DOMDocument property bridge (php-src ext/dom/document.c; #14420, #28587).
 */
final class DomDocumentPropertySupport
{
    /** php-src ext/dom/php_dom.c — DOMDocument::$documentElement is read-only (#15550). */
    public static function rejectReadOnlyPropertyWrite(ObjectEntry $owner, string $name): void
    {
        if (VmDom::CLASS_DOCUMENT !== strtolower($owner->class->name)) {
            return;
        }
        if (strtolower(VmDom::PROP_DOCUMENT_ELEMENT) === strtolower($name)) {
            throw new \Error(
                'Cannot write read-only property DOMDocument::$documentElement'
            );
        }
        if (strtolower(VmDom::PROP_XML_ENCODING) === strtolower($name)) {
            throw new \Error(
                'Cannot write read-only property DOMDocument::$xmlEncoding'
            );
        }
        // php-src document.c — actualEncoding / config are private(set) / write handlers throw (#28587).
        if (strtolower(VmDom::PROP_ACTUAL_ENCODING) === strtolower($name)) {
            throw new \Error(
                'Cannot write read-only property DOMDocument::$actualEncoding'
            );
        }
        if (strtolower(VmDom::PROP_CONFIG) === strtolower($name)) {
            throw new \Error(
                'Cannot write read-only property DOMDocument::$config'
            );
        }
    }

    public static function isManagedProperty(ObjectEntry $object, string $name): bool
    {
        if (VmDom::CLASS_DOCUMENT !== strtolower($object->class->name)) {
            return false;
        }
        $lc = strtolower($name);

        return strtolower(VmDom::PROP_ENCODING) === $lc
            || strtolower(VmDom::PROP_XML_ENCODING) === $lc
            || strtolower(VmDom::PROP_ACTUAL_ENCODING) === $lc
            || strtolower(VmDom::PROP_XML_VERSION) === $lc
            || strtolower(VmDom::PROP_VERSION) === $lc
            || strtolower(VmDom::PROP_XML_STANDALONE) === $lc
            || strtolower(VmDom::PROP_STANDALONE) === $lc
            || strtolower(VmDom::PROP_CONFIG) === $lc
            || strtolower(VmDom::PROP_DOCUMENT_URI) === $lc
            || strtolower(VmDom::PROP_IMPLEMENTATION) === $lc
            || strtolower(VmDom::PROP_DOCTYPE) === $lc;
    }

    public static function getProperty(ObjectEntry $object, string $name): Variable
    {
        VmDom::ensureDocument($object);
        $lc = strtolower($name);
        $state = DomRegistry::state($object);
        $var = new Variable();
        $var->objectPropertyOwner = $object;
        $var->objectPropertyName = $lc;
        if (strtolower(VmDom::PROP_ENCODING) === $lc
            || strtolower(VmDom::PROP_XML_ENCODING) === $lc
            || strtolower(VmDom::PROP_ACTUAL_ENCODING) === $lc) {
            if (null === $state->encoding) {
                $var->null();
            } else {
                $var->string($state->encoding);
            }

            return $var;
        }
        if (strtolower(VmDom::PROP_XML_VERSION) === $lc
            || strtolower(VmDom::PROP_VERSION) === $lc) {
            $var->string($state->xmlVersion);

            return $var;
        }
        if (strtolower(VmDom::PROP_XML_STANDALONE) === $lc
            || strtolower(VmDom::PROP_STANDALONE) === $lc) {
            $var->bool($state->xmlStandalone);

            return $var;
        }
        if (strtolower(VmDom::PROP_CONFIG) === $lc) {
            // php-src dom_document_config_read — always null (#28587).
            $var->null();

            return $var;
        }
        if (strtolower(VmDom::PROP_DOCUMENT_URI) === $lc) {
            if (null === $state->documentUri) {
                $var->null();
            } else {
                $var->string($state->documentUri);
            }

            return $var;
        }
        if (strtolower(VmDom::PROP_IMPLEMENTATION) === $lc) {
            $var->object(VmDom::implementationSingleton());

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

        throw new \LogicException('DomDocumentPropertySupport::getProperty() called with unmanaged name');
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
        VmDom::ensureDocument($owner);
        $lc = strtolower($propName);
        $state = DomRegistry::state($owner);
        $resolved = $value->resolveIndirect();
        if (strtolower(VmDom::PROP_ENCODING) === $lc) {
            if (Variable::TYPE_NULL === $resolved->type) {
                $state->encoding = null;
            } elseif (Variable::TYPE_STRING === $resolved->type) {
                $state->encoding = $resolved->toString();
            } else {
                throw new \TypeError(sprintf(
                    'Cannot assign %s to property DOMDocument::$encoding of type ?string',
                    VmDom::typeLabel($resolved)
                ));
            }

            return true;
        }
        if (strtolower(VmDom::PROP_XML_VERSION) === $lc
            || strtolower(VmDom::PROP_VERSION) === $lc) {
            // php-src ?string — null becomes "" (document.c version/xmlVersion write; #28587).
            if (Variable::TYPE_NULL === $resolved->type) {
                $state->xmlVersion = '';
            } elseif (Variable::TYPE_STRING === $resolved->type
                || Variable::TYPE_INTEGER === $resolved->type
                || Variable::TYPE_FLOAT === $resolved->type) {
                $state->xmlVersion = $resolved->toString();
            } else {
                $label = strtolower(VmDom::PROP_VERSION) === $lc
                    ? 'DOMDocument::$version'
                    : 'DOMDocument::$xmlVersion';
                throw new \TypeError(sprintf(
                    'Cannot assign %s to property %s of type ?string',
                    VmDom::typeLabel($resolved),
                    $label
                ));
            }

            return true;
        }
        if (strtolower(VmDom::PROP_XML_STANDALONE) === $lc
            || strtolower(VmDom::PROP_STANDALONE) === $lc) {
            $label = strtolower(VmDom::PROP_STANDALONE) === $lc
                ? 'DOMDocument::$standalone'
                : 'DOMDocument::$xmlStandalone';
            // php-src bool — null is TypeError; other scalars coerce (zend_parse bool; #28587).
            if (Variable::TYPE_NULL === $resolved->type) {
                throw new \TypeError(sprintf(
                    'Cannot assign null to property %s of type bool',
                    $label
                ));
            }
            if (Variable::TYPE_BOOLEAN === $resolved->type
                || Variable::TYPE_INTEGER === $resolved->type
                || Variable::TYPE_FLOAT === $resolved->type
                || Variable::TYPE_STRING === $resolved->type) {
                $state->xmlStandalone = $resolved->toBool();

                return true;
            }
            throw new \TypeError(sprintf(
                'Cannot assign %s to property %s of type bool',
                VmDom::typeLabel($resolved),
                $label
            ));
        }
        if (strtolower(VmDom::PROP_DOCUMENT_URI) === $lc) {
            if (Variable::TYPE_NULL === $resolved->type) {
                $state->documentUri = null;
            } elseif (Variable::TYPE_STRING === $resolved->type) {
                $state->documentUri = $resolved->toString();
            } else {
                throw new \TypeError(sprintf(
                    'Cannot assign %s to property DOMDocument::$documentURI of type ?string',
                    VmDom::typeLabel($resolved)
                ));
            }

            return true;
        }

        return false;
    }
}
