<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomSaveHTMLFile;
use PHPCompiler\JIT\Builtin\DomSaveHTMLFileRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::saveHTMLFile() — user-script AOT (#18268). */
final class DomDocumentSaveHTMLFile implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        DomSaveHTMLFileRuntime::ensureLinked($context);

        return JitDomSaveHTMLFile::invoke($context, ...$args);
    }
}
