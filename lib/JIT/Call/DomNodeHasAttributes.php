<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomHasAttributes;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::hasAttributes() — user-script AOT (php-src ext/dom/node.c xmlNode->properties) (#32458). */
final class DomNodeHasAttributes implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomHasAttributes::invoke($context, ...$args);
    }
}
