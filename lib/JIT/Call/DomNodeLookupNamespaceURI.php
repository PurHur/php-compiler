<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomLookupNamespaceURI;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMNode::lookupNamespaceURI() — user-script AOT (php-src xmlSearchNs) (#32504).
 */
final class DomNodeLookupNamespaceURI implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomLookupNamespaceURI::invoke($context, ...$args);
    }
}
