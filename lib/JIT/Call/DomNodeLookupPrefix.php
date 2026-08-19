<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomLookupPrefix;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMNode::lookupPrefix() — user-script AOT (php-src xmlSearchNsByHref) (#32493).
 */
final class DomNodeLookupPrefix implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomLookupPrefix::invoke($context, ...$args);
    }
}
