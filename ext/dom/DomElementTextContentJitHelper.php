<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** DOMElement::$textContent for user-script AOT property reads/writes (#17954, #23251). */
final class DomElementTextContentJitHelper
{
    public static function textContentArgv(ObjectEntry $element): string
    {
        return VmDom::readTextContent($element);
    }

    /** php-src dom_node_textContent_write — detach children + insert text (#20646, #23251). */
    public static function writeTextContentArgv(ObjectEntry $element, string $value): void
    {
        $ctx = \PHPCompiler\VM\VmActiveContextJitHelper::resolve();
        VmDom::writeTextContent($ctx, $element, $value);
    }

    /** php-src dom_node_node_value_write (#20646, #23251). */
    public static function writeNodeValueArgv(ObjectEntry $element, string $value): void
    {
        $ctx = \PHPCompiler\VM\VmActiveContextJitHelper::resolve();
        VmDom::writeNodeValue($ctx, $element, $value);
    }
}
