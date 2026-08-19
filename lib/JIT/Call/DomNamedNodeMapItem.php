<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomNamedNodeMap;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNamedNodeMap::item() — user-script AOT (php-src namednodemap.c) (#32546). */
final class DomNamedNodeMapItem implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomNamedNodeMap::invokeItem($context, ...$args);
    }
}
