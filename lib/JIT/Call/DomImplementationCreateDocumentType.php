<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateDocumentType;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMImplementation::createDocumentType() — user-script AOT (#19797). */
final class DomImplementationCreateDocumentType implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateDocumentType::invoke($context, ...$args);
    }
}
