<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomLoadXML;
use PHPCompiler\JIT\Builtin\DomLoadXMLRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::loadXML() — user-script AOT (#18268). */
final class DomDocumentLoadXML implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        DomLoadXMLRuntime::ensureLinked($context);

        return JitDomLoadXML::invoke($context, ...$args);
    }
}
