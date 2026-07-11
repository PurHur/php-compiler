<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** DOMElement::$textContent for user-script AOT property reads (#17954). */
final class DomElementTextContentJitHelper
{
    public static function textContentArgv(ObjectEntry $element): string
    {
        return VmDom::readTextContent($element);
    }
}
