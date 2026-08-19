<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomIsSupported;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMNode::isSupported() — user-script AOT (php-src dom_has_feature) (#32480).
 */
final class DomNodeIsSupported implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomIsSupported::invoke($context, ...$args);
    }
}
