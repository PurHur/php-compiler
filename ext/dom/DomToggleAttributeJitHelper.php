<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMElement::toggleAttribute() — user-script AOT (#19507).
 *
 * Nested VmDom bool/int presence is unreliable; keep a class-static mirror keyed by
 * element id + name so omit add/remove returns match php-src across calls in one process.
 * php-src: ext/dom/element.c — dom_element_toggle_attribute
 */
final class DomToggleAttributeJitHelper
{
    /** @var array<string, bool> */
    private static array $presentMirror = [];

    public static function toggleOmitArgv(Context $ctx, ObjectEntry $element, string $name): bool
    {
        $key = $element->id."\0".$name;
        if (!isset(self::$presentMirror[$key])) {
            VmDom::setAttributeNS($ctx, $element, null, $name, '');
            self::$presentMirror[$key] = true;

            return true;
        }
        VmDom::removeAttributeNS($ctx, $element, null, $name);
        unset(self::$presentMirror[$key]);

        return false;
    }

    public static function toggleForceTrueArgv(Context $ctx, ObjectEntry $element, string $name): bool
    {
        VmDom::setAttributeNS($ctx, $element, null, $name, '');
        self::$presentMirror[$element->id."\0".$name] = true;

        return true;
    }

    public static function toggleForceFalseArgv(Context $ctx, ObjectEntry $element, string $name): bool
    {
        VmDom::removeAttributeNS($ctx, $element, null, $name);
        unset(self::$presentMirror[$element->id."\0".$name]);

        return false;
    }
}
