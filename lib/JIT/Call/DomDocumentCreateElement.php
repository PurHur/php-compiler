<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateElement;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::createElement() — user-script AOT (#17391, #15407). */
final class DomDocumentCreateElement implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateElement::invoke($context, ...$args);
    }
}
