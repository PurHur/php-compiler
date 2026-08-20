<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomInsertAdjacent;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::insertAdjacentText() — user-script AOT (ext/dom/element.c). */
final class DomElementInsertAdjacentText implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_insert_adjacent_text_invoke_cont');

        return JitDomInsertAdjacent::invokeText($context, ...$args);
    }
}
