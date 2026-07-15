<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomLoadHTMLFile;
use PHPCompiler\JIT\Builtin\DomLoadHTMLFileRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::loadHTMLFile() — user-script AOT (#18734). */
final class DomDocumentLoadHTMLFile implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        DomLoadHTMLFileRuntime::ensureLinked($context);

        return JitDomLoadHTMLFile::invoke($context, ...$args);
    }
}
