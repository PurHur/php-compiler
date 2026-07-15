<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateElementNS;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::createElementNS() — user-script AOT (#14314, #18938). */
final class DomDocumentCreateElementNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateElementNS::invoke($context, ...$args);
    }
}
