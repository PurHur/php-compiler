<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateProcessingInstruction;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::createProcessingInstruction() — user-script AOT (#32331). */
final class DomDocumentCreateProcessingInstruction implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateProcessingInstruction::invoke($context, ...$args);
    }
}
