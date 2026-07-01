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
            || strtolower(VmDom::PROP_OWNER_DOCUMENT) === $lc
            || strtolower(VmDom::PROP_NODE_VALUE) === $lc;
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
        if (strtolower(VmDom::PROP_OWNER_DOCUMENT) === $lc) {
            $owner = VmDom::ownerDocumentEntry($object);
            if (null === $owner) {
                $var->null();
            } else {
                $var->object($owner);
            }

            return $var;
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
        if (strtolower(VmDom::PROP_NODE_VALUE) !== strtolower($propName)) {
            return false;
        }
        if (!DomRegistry::has($owner)) {
            return false;
        }
        $resolved = $value->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            VmDom::writeNodeValue($ctx, $owner, '');
        } elseif (Variable::TYPE_STRING === $resolved->type) {
            VmDom::writeNodeValue($ctx, $owner, $resolved->toString());
        } else {
            VmDom::writeNodeValue($ctx, $owner, $resolved->toString());
        }

        return true;
    }
}
