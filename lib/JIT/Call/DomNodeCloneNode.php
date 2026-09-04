<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::cloneNode() — user-script AOT (php-src ext/dom/node.c xmlDocCopyNode) (#32355). */
final class DomNodeCloneNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'node.cloneNode',
            ...$args
        );
    }
}
