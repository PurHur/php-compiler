<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomNamedNodeMap;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNamedNodeMap::getNamedItem() — user-script AOT pin scan (php-src namednodemap.c) (#33107). */
final class DomNamedNodeMapGetNamedItem implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomNamedNodeMap::invokeGetNamedItem($context, ...$args);
    }
}
