<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomGetElementsByTagName;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::getElementsByTagName() — user-script AOT (#18461, #18478). */
final class DomDocumentGetElementsByTagName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomGetElementsByTagName::invoke($context, ...$args);
    }
}
