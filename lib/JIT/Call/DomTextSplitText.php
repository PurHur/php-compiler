<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMText::splitText() — user-script AOT (php-src ext/dom/text.c xmlTextSplitText) (#32362). */
final class DomTextSplitText implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'text.splitText',
            ...$args
        );
    }
}
