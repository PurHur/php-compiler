<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::prepend() — user-script AOT ParentNode (#18951) — thin proxy via DomExtensionHooks (#36204). */
final class DomNodePrepend implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'node.prepend',
            ...$args
        );
    }
}
