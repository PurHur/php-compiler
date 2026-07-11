<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomLoadHTML;
use PHPCompiler\JIT\Builtin\DomLoadHTMLRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::loadHTML() — user-script AOT (#17954). */
final class DomDocumentLoadHTML implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        DomLoadHTMLRuntime::ensureLinked($context);

        return JitDomLoadHTML::invoke($context, ...$args);
    }
}
