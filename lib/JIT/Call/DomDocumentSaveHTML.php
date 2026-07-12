<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomSaveHTML;
use PHPCompiler\JIT\Builtin\DomSaveHTMLRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::saveHTML() — user-script AOT (#18268). */
final class DomDocumentSaveHTML implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        DomSaveHTMLRuntime::ensureLinked($context);

        return JitDomSaveHTML::invoke($context, ...$args);
    }
}
