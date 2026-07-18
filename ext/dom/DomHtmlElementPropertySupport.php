<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Reflected Dom\Element / Dom\HTMLElement IDL attributes
 * (php-src element.c / inner_outer_html_mixin.c; #20418, #20532).
 */
final class DomHtmlElementPropertySupport
{
    public const PROP_ID = 'id';

    public const PROP_CLASS_NAME = 'className';

    public const PROP_INNER_HTML = 'innerHTML';

    public const PROP_OUTER_HTML = 'outerHTML';

    public static function isManagedProperty(ObjectEntry $object, string $name): bool
    {
        if (!VmDomLiving::isLivingElement($object) || !VmDom::isElement($object)) {
            return false;
        }
        $lc = strtolower($name);

        return 'id' === $lc
            || 'classname' === $lc
            || 'innerhtml' === $lc
            || 'outerhtml' === $lc;
    }

    /**
     * isset($el->id|/className|/innerHTML|/outerHTML) — typed string props, not the null ClassProperty slot (#20532).
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
     * empty($el->…) — Zend has_property then value truthiness (#20532).
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
        $lc = strtolower($name);
        $var = new Variable();
        $var->objectPropertyOwner = $object;
        $var->objectPropertyName = $lc;
        if ('id' === $lc) {
            $var->string(VmDom::getAttribute($object, 'id'));

            return $var;
        }
        if ('classname' === $lc) {
            $var->string(VmDom::getAttribute($object, 'class'));

            return $var;
        }
        if ('innerhtml' === $lc) {
            $var->string(VmDom::getInnerHTML($object));

            return $var;
        }
        if ('outerhtml' === $lc) {
            $var->string(VmDom::getOuterHTML($object));

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
        $lc = strtolower($propName);
        $resolved = $value->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type || Variable::TYPE_BOOLEAN === $resolved->type) {
            // Absorb ClassProperty default init.
            return true;
        }
        $propLabel = match ($lc) {
            'id' => 'id',
            'classname' => 'className',
            'innerhtml' => 'innerHTML',
            default => 'outerHTML',
        };
        if (Variable::TYPE_STRING !== $resolved->type) {
            throw new \TypeError(sprintf(
                'Cannot assign %s to property Dom\\Element::$%s of type string',
                VmDom::typeLabel($resolved),
                $propLabel
            ));
        }
        $string = $resolved->toString();
        if ('id' === $lc) {
            VmDom::setAttributeNS($ctx, $owner, null, 'id', $string);

            return true;
        }
        if ('classname' === $lc) {
            VmDom::setAttributeNS($ctx, $owner, null, 'class', $string);

            return true;
        }
        if ('innerhtml' === $lc) {
            VmDom::setInnerHTML($ctx, $owner, $string);

            return true;
        }
        if ('outerhtml' === $lc) {
            VmDom::setOuterHTML($ctx, $owner, $string);

            return true;
        }

        return false;
    }
}
