<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomGetNodePath;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::getNodePath() — user-script AOT (php-src ext/dom/node.c xmlGetNodePath) (#32474). */
final class DomNodeGetNodePath implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomGetNodePath::invoke($context, ...$args);
    }
}
