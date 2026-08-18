<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateEntityReference;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::createEntityReference() — user-script AOT (#32343). */
final class DomDocumentCreateEntityReference implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateEntityReference::invoke($context, ...$args);
    }
}
