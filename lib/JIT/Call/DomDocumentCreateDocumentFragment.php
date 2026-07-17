<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateDocumentFragment;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::createDocumentFragment() — user-script AOT (#18951, #20203). */
final class DomDocumentCreateDocumentFragment implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateDocumentFragment::invoke($context, ...$args);
    }
}
