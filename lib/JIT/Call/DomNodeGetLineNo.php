<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomGetLineNo;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::getLineNo() — user-script AOT (php-src xmlGetLineNo) (#32489). */
final class DomNodeGetLineNo implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomGetLineNo::invoke($context, ...$args);
    }
}
