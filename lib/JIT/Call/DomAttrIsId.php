<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMAttr::isId() — user-script AOT (#29884) — thin proxy via DomExtensionHooks (#36204). */
final class DomAttrIsId implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'attr.isId',
            ...$args
        );
    }
}
