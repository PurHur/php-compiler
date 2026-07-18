<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Reflected Dom\HTMLElement / Dom\Element IDL attributes (php-src html5_serializer / element; #20418).
 */
final class DomHtmlElementPropertySupport
{
    public static function isManagedProperty(ObjectEntry $object, string $name): bool
    {
        if (!VmDomLiving::isLivingElement($object) || !VmDom::isElement($object)) {
            return false;
        }

        return 'id' === strtolower($name);
    }

    public static function getProperty(ObjectEntry $object, string $name): Variable
    {
        $lc = strtolower($name);
        $var = new Variable();
        $var->objectPropertyOwner = $object;
        $var->objectPropertyName = $lc;
        if ('id' === $lc) {
            $var->string(VmDom::getAttribute($object, 'id'));

            return $var;
        }

        throw new \LogicException('DomHtmlElementPropertySupport::getProperty() called with unmanaged name');
    }

    /**
     * @return bool true when the write was handled
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
        $resolved = $value->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type || Variable::TYPE_BOOLEAN === $resolved->type) {
            // Absorb ClassProperty default init.
            return true;
        }
        if (Variable::TYPE_STRING !== $resolved->type) {
            throw new \TypeError(sprintf(
                'Cannot assign %s to property Dom\\Element::$id of type string',
                VmDom::typeLabel($resolved)
            ));
        }
        VmDom::setAttributeNS($ctx, $owner, null, 'id', $resolved->toString());

        return true;
    }
}
