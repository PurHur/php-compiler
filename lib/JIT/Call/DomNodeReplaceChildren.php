<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::replaceChildren() — user-script AOT (#18951) — thin proxy via DomExtensionHooks (#36204). */
final class DomNodeReplaceChildren implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'node.replaceChildren',
            ...$args
        );
    }
}
