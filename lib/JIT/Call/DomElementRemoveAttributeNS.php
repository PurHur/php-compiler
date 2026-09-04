<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::removeAttributeNS() — user-script AOT (#32398, php-src returns null) — thin proxy via DomExtensionHooks (#36204). */
final class DomElementRemoveAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'element.removeAttributeNS',
            ...$args
        );
    }
}
