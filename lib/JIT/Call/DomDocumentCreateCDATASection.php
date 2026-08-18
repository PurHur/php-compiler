<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateCDATASection;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::createCDATASection() — user-script AOT (#32327). */
final class DomDocumentCreateCDATASection implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateCDATASection::invoke($context, ...$args);
    }
}
