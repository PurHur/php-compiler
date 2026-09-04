<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::getElementsByTagNameNS() — user-script AOT (#32511, ext/dom/element.c). */
final class DomElementGetElementsByTagNameNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'element.getElementsByTagNameNS',
            ...$args
        );
    }
}
