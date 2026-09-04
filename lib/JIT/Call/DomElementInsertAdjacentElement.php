<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::insertAdjacentElement() — user-script AOT (ext/dom/php_dom.c). */
final class DomElementInsertAdjacentElement implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'element.insertAdjacentElement',
            ...$args
        );
    }
}
