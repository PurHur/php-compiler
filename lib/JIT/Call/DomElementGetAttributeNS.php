<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Dom\Element::getAttributeNS() — thin user-script AOT live Attr cache (#27108) — thin proxy via DomExtensionHooks (#36204). */
final class DomElementGetAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'element.getAttributeNS',
            ...$args
        );
    }
}
