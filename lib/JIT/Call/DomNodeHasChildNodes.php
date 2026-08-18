<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomHasChildNodes;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::hasChildNodes() — user-script AOT (php-src ext/dom/node.c xmlNode->children) (#32427). */
final class DomNodeHasChildNodes implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomHasChildNodes::invoke($context, ...$args);
    }
}
