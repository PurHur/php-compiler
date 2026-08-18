<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCloneNode;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::cloneNode() — user-script AOT (php-src ext/dom/node.c xmlDocCopyNode) (#32355). */
final class DomNodeCloneNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCloneNode::invoke($context, ...$args);
    }
}
