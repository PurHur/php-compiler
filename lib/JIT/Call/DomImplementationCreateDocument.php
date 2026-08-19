<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateDocument;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMImplementation::createDocument() — user-script AOT (#32531).
 *
 * php-src: ext/dom/php_dom.c PHP_METHOD(DOMImplementation, createDocument)
 */
final class DomImplementationCreateDocument implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateDocument::invoke($context, ...$args);
    }
}
