<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateTextNode;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::createTextNode() — user-script AOT (#32315). */
final class DomDocumentCreateTextNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateTextNode::invoke($context, ...$args);
    }
}
