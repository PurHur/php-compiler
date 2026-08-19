<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomLookupNamespaceURI;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMNode::isDefaultNamespace() — user-script AOT (php-src xmlSearchNs NULL) (#32504).
 */
final class DomNodeIsDefaultNamespace implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomLookupNamespaceURI::invokeIsDefaultNamespace($context, ...$args);
    }
}
