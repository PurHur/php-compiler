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
    /**
     * php-src ext/dom/php_dom.c — DOMAttr::$specified is read-only (#20605).
     * Dom\* ParentNode::$children is private(set) when advertised (PHP 8.5+; #21559).
     * NodeList / NamedNodeMap / CharacterData / TokenList::$length (#31707).
     */
    public static function rejectReadOnlyPropertyWrite(ObjectEntry $owner, string $name): void
    {
        if (VmDom::isXPath($owner) && strtolower(VmDom::PROP_XPATH_DOCUMENT) === strtolower($name)) {
            throw new \Error(
                'Cannot write read-only property '.$owner->class->name.'::$document'
            );
        }
        if (VmDom::isLivingParentNodeForChildren($owner)
            && strtolower(VmDom::PROP_CHILDREN) === strtolower($name)
        ) {
            throw new \Error(
                'Cannot write read-only property '.$owner->class->name.'::$children'
            );
        }
        // Living Dom\* Document / Element / DocumentFragment::$nodeValue is readonly null
        // (php-src modern stub; #21054). Legacy DOMElement remains writable.
        if (strtolower(VmDom::PROP_NODE_VALUE) === strtolower($name)
            && str_starts_with(strtolower($owner->class->name), 'dom\\')
            && (VmDom::isDocument($owner)
                || VmDom::isElement($owner)
                || VmDom::isDocumentFragment($owner))
        ) {
            throw new \Error(
                'Cannot modify readonly property '.$owner->class->name.'::$nodeValue'
            );
        }
        // php-src nodelist.c / nodemap.c / characterdata.c / token_list.c — @$length (#31707).
        if (strtolower(VmDom::PROP_LENGTH) === strtolower($name)
            && self::hasReadOnlyLengthProperty($owner)
        ) {
            throw new \Error(
                'Cannot write read-only property '.$owner->class->name.'::$length'
            );
        }
        if (!VmDom::isAttr($owner)) {
            return;
        }
        if (strtolower(VmDom::PROP_SPECIFIED) === strtolower($name)) {
            throw new \Error(
                'Cannot write read-only property DOMAttr::$specified'
            );
        }
    }

    /**
     * Classes whose `$length` property is engine-managed read-only (php-src stubs; #31707).
     */
    private static function hasReadOnlyLengthProperty(ObjectEntry $owner): bool
    {
        if (VmDom::isNodeList($owner)
            || VmDom::isHtmlCollection($owner)
            || VmDom::isNamedNodeMap($owner)
            || VmDom::isTokenList($owner)
            || VmDom::isCharacterData($owner)
        ) {
            return true;
        }
        $lc = strtolower($owner->class->name);

        return VmDom::CLASS_NODE_LIST === $lc
            || VmDom::CLASS_NAMED_NODE_MAP === $lc
            || VmDom::CLASS_TOKEN_LIST === $lc
            || VmDomLiving::CLASS_NODE_LIST === $lc
            || VmDomLiving::CLASS_NAMED_NODE_MAP === $lc
            || VmDomLiving::CLASS_DTD_NAMED_NODE_MAP === $lc
            || VmDomLiving::CLASS_HTML_COLLECTION === $lc
            || VmDomLiving::CLASS_TOKEN_LIST === $lc
            || VmDom::CLASS_CHARACTER_DATA === $lc
            || VmDom::CLASS_TEXT === $lc
            || VmDom::CLASS_COMMENT === $lc
            || VmDom::CLASS_CDATA === $lc
            || VmDomLiving::CLASS_CHARACTER_DATA === $lc
            || VmDomLiving::CLASS_TEXT === $lc
            || VmDomLiving::CLASS_COMMENT === $lc
            || VmDomLiving::CLASS_CDATA === $lc;
    }

    public static function isManagedProperty(ObjectEntry $object, string $name): bool
    {
        if (!DomRegistry::has($object)) {
            return false;
        }
        $lc = strtolower($name);

        // DOMXPath / Dom\XPath computed props (php-src php_dom.stub.php; #20842).
        if (VmDom::isXPath($object)) {
            return strtolower(VmDom::PROP_REGISTER_NODE_NAMESPACES) === $lc
                || strtolower(VmDom::PROP_XPATH_DOCUMENT) === $lc;
        }

        // Dom\* ParentNode::$children (PHP 8.5+; #21559, re-#21033).
        if (VmDom::isLivingParentNodeForChildren($object)
            && strtolower(VmDom::PROP_CHILDREN) === $lc
        ) {
            return true;
        }

        return strtolower(VmDom::PROP_NODE_TYPE) === $lc
            || strtolower(VmDom::PROP_NODE_NAME) === $lc
            || (VmDom::isElement($object) && strtolower(VmDom::PROP_TAG_NAME) === $lc)
            || strtolower(VmDom::PROP_OWNER_DOCUMENT) === $lc
            || (VmDom::isElement($object) && strtolower(VmDom::PROP_ATTRIBUTES) === $lc)
            || (VmDom::exposesChildNodes($object) && strtolower(VmDom::PROP_CHILD_NODES) === $lc)
            || strtolower(VmDom::PROP_NODE_VALUE) === $lc
            || strtolower(VmDom::PROP_TEXT_CONTENT) === $lc
            || strtolower(VmDom::PROP_BASE_URI) === $lc
            || strtolower(VmDom::PROP_NAMESPACE_URI) === $lc
            || strtolower(VmDom::PROP_LOCAL_NAME) === $lc
            || strtolower(VmDom::PROP_PREFIX) === $lc
            || (VmDom::isAttr($object) && strtolower(VmDom::PROP_NAME) === $lc)
            || (VmDom::isAttr($object) && strtolower(VmDom::PROP_VALUE) === $lc)
            || (VmDom::isAttr($object) && strtolower(VmDom::PROP_OWNER_ELEMENT) === $lc)
            || (VmDom::isAttr($object) && strtolower(VmDom::PROP_SPECIFIED) === $lc)
            || (VmDom::isCharacterData($object) && strtolower(VmDom::PROP_DATA) === $lc)
            || (VmDom::isCharacterData($object) && strtolower(VmDom::PROP_LENGTH) === $lc)
            || (VmDom::isTextOrCdataNode($object) && strtolower(VmDom::PROP_WHOLE_TEXT) === $lc)
            || (VmDom::isNodeList($object) && strtolower(VmDom::PROP_LENGTH) === $lc)
            || (VmDom::isNamedNodeMap($object) && strtolower(VmDom::PROP_LENGTH) === $lc)
            || (\PHPCompiler\CompilerVersion::supportsDomNodeIsConnected()
                && strtolower(VmDom::PROP_IS_CONNECTED) === $lc);
    }

    /**
     * isset($node->…) — computed Dom/DOMNode props, not null ClassProperty slots
     * (php-src zend_std_has_property / node.c; #21033 children, #21053 Node, #21055 CharacterData).
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
     * empty($node->…) — Zend has_property then value truthiness (#21033, #21053, #21055).
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

    public static function getProperty(ObjectEntry $object, string $name, ?Context $ctx = null): Variable
    {
        VmDom::ensureFetchableNode($object);
        $lc = strtolower($name);
        $var = new Variable();
        $var->objectPropertyOwner = $object;
        $var->objectPropertyName = $lc;

        if (VmDom::isXPath($object)) {
            $state = DomRegistry::state($object);
            if (strtolower(VmDom::PROP_REGISTER_NODE_NAMESPACES) === $lc) {
                $var->bool($state->xpathRegisterNodeNamespaces);

                return $var;
            }
            if (strtolower(VmDom::PROP_XPATH_DOCUMENT) === $lc) {
                $document = DomRegistry::entry($state->xpathDocumentId ?? 0);
                if (null === $document) {
                    $var->null();
                } else {
                    $var->object($document);
                }

                return $var;
            }
        }

        // Dom\* ParentNode::$children (PHP 8.5+ html_collection.c; #21559).
        if (VmDom::isLivingParentNodeForChildren($object)
            && strtolower(VmDom::PROP_CHILDREN) === $lc
        ) {
            if (null === $ctx) {
                $ctx = \PHPCompiler\VM\VmActiveContextJitHelper::resolve();
            }
            VmDom::ensureChildrenCollection($ctx, $object);

            return VmDom::parentNodeChildrenVariable($object);
        }

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
            // HTML Dom\Element tagName/nodeName are uppercase (php-src element.c; #21558).
            $var->string(VmDom::readNodeName($object));

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

            // Zend creates a fresh NamedNodeMap wrapper per read (php-src; #26330).
            return VmDom::issueElementAttributesMap($ctx, $object);
        }
        if (VmDom::exposesChildNodes($object) && strtolower(VmDom::PROP_CHILD_NODES) === $lc) {
            if (null === $ctx) {
                $ctx = \PHPCompiler\VM\VmActiveContextJitHelper::resolve();
            }

            // Zend creates a fresh NodeList wrapper per read (php-src; #26330).
            return VmDom::issueChildNodesList($ctx, $object);
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
            // Living Dom\* nodes: empty prefix is null (php-src php_dom.stub.php; #20924).
            // Legacy DOM*: empty string.
            if (!DomRegistry::has($object)) {
                $var->string('');

                return $var;
            }
            $prefix = DomRegistry::state($object)->prefix;
            if (null === $prefix || '' === $prefix) {
                if (VmDomLiving::isLivingElement($object)
                    || (isset($object->class->name)
                        && str_starts_with(strtolower($object->class->name), 'dom\\'))) {
                    $var->null();

                    return $var;
                }
                $var->string('');

                return $var;
            }
            $var->string($prefix);

            return $var;
        }
        // php-src ext/dom/attr.c dom_attr_name_read — living follow_spec → QName
        // (dom_node_get_node_name_attribute_or_element; #26024); legacy → local (#19754).
        if (VmDom::isAttr($object) && strtolower(VmDom::PROP_NAME) === $lc) {
            if (VmDomLiving::isLivingAttr($object)) {
                $var->string(VmDom::readNodeName($object));
            } else {
                $var->string(VmDom::readLocalName($object));
            }

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
        // php-src ext/dom/attr.c dom_attr_specified_read — always true (#20605).
        if (VmDom::isAttr($object) && strtolower(VmDom::PROP_SPECIFIED) === $lc) {
            $var->bool(true);

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
        if (VmDom::isNamedNodeMap($object) && strtolower(VmDom::PROP_LENGTH) === $lc) {
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
        if (VmDom::isXPath($owner) && strtolower(VmDom::PROP_REGISTER_NODE_NAMESPACES) === $lc) {
            $resolved = $value->resolveIndirect();
            // php-src zend_is_true for the write handler.
            DomRegistry::state($owner)->xpathRegisterNodeNamespaces = $resolved->toBool();

            return true;
        }
        if (VmDom::isXPath($owner) && strtolower(VmDom::PROP_XPATH_DOCUMENT) === $lc) {
            throw new \Error('Cannot write read-only property '.$owner->class->name.'::$document');
        }
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
        // Attr::$value only — TokenList::$value is DomTokenListPropertySupport (#24545).
        if (strtolower(VmDom::PROP_VALUE) === $lc && !VmDom::isAttr($owner)) {
            return false;
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
