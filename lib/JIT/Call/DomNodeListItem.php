<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNodeList::item() — user-script AOT (#18493). */
final class DomNodeListItem implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'nodeList.item',
            ...$args
        );
    }
}
