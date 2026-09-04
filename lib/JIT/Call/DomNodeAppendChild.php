<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::appendChild() — user-script AOT (#18478, #18927, #27044, #27480) — thin proxy via DomExtensionHooks (#36204). */
final class DomNodeAppendChild implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'node.appendChild',
            ...$args
        );
    }
}
