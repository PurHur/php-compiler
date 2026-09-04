<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNamedNodeMap::getNamedItemNS() — user-script AOT pin scan (php-src namednodemap.c) (#33116). */
final class DomNamedNodeMapGetNamedItemNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'namedNodeMap.getNamedItemNS',
            ...$args
        );
    }
}
