<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Computed DOMNode property bridge (php-src ext/dom/node.c; #14417).
 *
 * nodeType / ownerDocument / nodeValue are derived from {@see DomRegistry} state.
 */
final class DomNodePropertySupport
{
    public static function isManagedProperty(ObjectEntry $object, string $name): bool
    {
        if (!DomRegistry::has($object)) {
            return false;
        }
        $lc = strtolower($name);

        return strtolower(VmDom::PROP_NODE_TYPE) === $lc
            || strtolower(VmDom::PROP_NODE_NAME) === $lc
            || (VmDom::isElement($object) && strtolower(VmDom::PROP_TAG_NAME) === $lc)
            || strtolower(VmDom::PROP_OWNER_DOCUMENT) === $lc
            || (VmDom::isElement($object) && strtolower(VmDom::PROP_ATTRIBUTES) === $lc)
            || strtolower(VmDom::PROP_NODE_VALUE) === $lc
            || strtolower(VmDom::PROP_TEXT_CONTENT) === $lc
            || strtolower(VmDom::PROP_BASE_URI) === $lc
            || strtolower(VmDom::PROP_NAMESPACE_URI) === $lc
            || strtolower(VmDom::PROP_LOCAL_NAME) === $lc
            || strtolower(VmDom::PROP_PREFIX) === $lc
            || (VmDom::isAttr($object) && strtolower(VmDom::PROP_NAME) === $lc)
            || (VmDom::isAttr($object) && strtolower(VmDom::PROP_VALUE) === $lc)
            || (VmDom::isAttr($object) && strtolower(VmDom::PROP_OWNER_ELEMENT) === $lc)
            || (VmDom::isCharacterData($object) && strtolower(VmDom::PROP_DATA) === $lc)
            || (VmDom::isCharacterData($object) && strtolower(VmDom::PROP_LENGTH) === $lc)
            || (VmDom::isTextOrCdataNode($object) && strtolower(VmDom::PROP_WHOLE_TEXT) === $lc)
            || (VmDom::isNodeList($object) && strtolower(VmDom::PROP_LENGTH) === $lc)
            || (\PHPCompiler\CompilerVersion::supportsDomNodeIsConnected()
                && strtolower(VmDom::PROP_IS_CONNECTED) === $lc);
    }

    public static function getProperty(ObjectEntry $object, string $name, ?Context $ctx = null): Variable
    {
        $lc = strtolower($name);
        $var = new Variable();
        $var->objectPropertyOwner = $object;
        $var->objectPropertyName = $lc;
        if (strtolower(VmDom::PROP_NODE_TYPE) === $lc) {
            $var->int(DomRegistry::state($object)->nodeType);

            return $var;
        }
        if (\PHPCompiler\CompilerVersion::supportsDomNodeIsConnected()
            && strtolower(VmDom::PROP_IS_CONNECTED) === $lc
        ) {
            $var->bool(VmDom::isConnected($object));

            return $var;
        }
        if (strtolower(VmDom::PROP_NODE_NAME) === $lc || strtolower(VmDom::PROP_TAG_NAME) === $lc) {
            $var->string(DomRegistry::state($object)->nodeName);

            return $var;
        }
        if (strtolower(VmDom::PROP_OWNER_DOCUMENT) === $lc) {
            $owner = VmDom::ownerDocumentEntry($object);
            if (null === $owner) {
                $var->null();
            } else {
                $var->object($owner);
            }

            return $var;
        }
        if (VmDom::isElement($object) && strtolower(VmDom::PROP_ATTRIBUTES) === $lc) {
            if (null === $ctx) {
                $ctx = \PHPCompiler\VM\VmActiveContextJitHelper::resolve();
            }
            VmDom::ensureElementAttributesMap($ctx, $object);

            return VmDom::elementAttributesVariable($object);
        }
        if (strtolower(VmDom::PROP_NODE_VALUE) === $lc) {
            $value = VmDom::readNodeValue($object);
            if (null === $value) {
                $var->null();
            } else {
                $var->string($value);
            }

            return $var;
        }
        if (strtolower(VmDom::PROP_TEXT_CONTENT) === $lc) {
            $var->string(VmDom::readTextContent($object));

            return $var;
        }
        if (strtolower(VmDom::PROP_BASE_URI) === $lc) {
            $var->string(VmDom::readBaseUri($object));

            return $var;
        }
        if (strtolower(VmDom::PROP_NAMESPACE_URI) === $lc) {
            $ns = VmDom::readNamespaceUri($object);
            if (null === $ns) {
                $var->null();
            } else {
                $var->string($ns);
            }

            return $var;
        }
        if (strtolower(VmDom::PROP_LOCAL_NAME) === $lc) {
            $var->string(VmDom::readLocalName($object));

            return $var;
        }
        if (strtolower(VmDom::PROP_PREFIX) === $lc) {
            $var->string(VmDom::readPrefix($object));

            return $var;
        }
        // php-src ext/dom/attr.c dom_attr_name_read — local name, not QName (#19754).
        if (VmDom::isAttr($object) && strtolower(VmDom::PROP_NAME) === $lc) {
            $var->string(VmDom::readLocalName($object));

            return $var;
        }
        if (VmDom::isAttr($object) && strtolower(VmDom::PROP_VALUE) === $lc) {
            $var->string(VmDom::readNodeValue($object) ?? '');

            return $var;
        }
        if (VmDom::isAttr($object) && strtolower(VmDom::PROP_OWNER_ELEMENT) === $lc) {
            $ownerId = DomRegistry::state($object)->ownerElementId;
            if (null === $ownerId) {
                $var->null();
            } else {
                $owner = DomRegistry::entry($ownerId);
                if (null === $owner) {
                    $var->null();
                } else {
                    $var->object($owner);
                }
            }

            return $var;
        }
        if (VmDom::isCharacterData($object) && strtolower(VmDom::PROP_DATA) === $lc) {
            $var->string(DomRegistry::state($object)->textContent ?? '');

            return $var;
        }
        if (VmDom::isCharacterData($object) && strtolower(VmDom::PROP_LENGTH) === $lc) {
            $var->int(\strlen(DomRegistry::state($object)->textContent ?? ''));

            return $var;
        }
        if (VmDom::isTextOrCdataNode($object) && strtolower(VmDom::PROP_WHOLE_TEXT) === $lc) {
            $var->string(VmDom::readWholeText($object));

            return $var;
        }
        if (VmDom::isNodeList($object) && strtolower(VmDom::PROP_LENGTH) === $lc) {
            VmDom::refreshNodeListIfLive($object);
            $var->int(\count(DomRegistry::state($object)->listNodeIds));

            return $var;
        }

        throw new \LogicException('DomNodePropertySupport::getProperty() called with unmanaged name');
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
        $lc = strtolower($propName);
        if (strtolower(VmDom::PROP_NODE_VALUE) !== $lc
            && strtolower(VmDom::PROP_TEXT_CONTENT) !== $lc
            && strtolower(VmDom::PROP_VALUE) !== $lc
            && strtolower(VmDom::PROP_DATA) !== $lc
        ) {
            return false;
        }
        if (!DomRegistry::has($owner)) {
            return false;
        }
        // CharacterData::$data write (php-src ext/dom/characterdata.c dom_characterdata_data_write; #19295).
        if (strtolower(VmDom::PROP_DATA) === $lc) {
            if (!VmDom::isCharacterData($owner)) {
                return false;
            }
        }
        $resolved = $value->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            $text = '';
        } elseif (Variable::TYPE_STRING === $resolved->type) {
            $text = $resolved->toString();
        } else {
            $text = $resolved->toString();
        }
        if (strtolower(VmDom::PROP_TEXT_CONTENT) === $lc) {
            VmDom::writeTextContent($ctx, $owner, $text);
        } elseif (strtolower(VmDom::PROP_DATA) === $lc) {
            VmDom::writeCharacterDataContent($owner, $text);
        } else {
            VmDom::writeNodeValue($ctx, $owner, $text);
        }

        return true;
    }
}
