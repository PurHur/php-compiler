<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::setAttributeNS() — user-script AOT (#32398, php-src xmlSetNsProp) — thin proxy via DomExtensionHooks (#36204). */
final class DomElementSetAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'element.setAttributeNS',
            ...$args
        );
    }
}
