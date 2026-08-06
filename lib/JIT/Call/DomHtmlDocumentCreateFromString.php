<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomHtmlDocumentCreateFromString;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Dom\HTMLDocument::createFromString() — user-script AOT (#27300).
 *
 * Avoids ExternalMethod silent NULL / abort on thin standalone AOT.
 */
final class DomHtmlDocumentCreateFromString implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'Dom\\HTMLDocument::createFromString';

    /** @var list<string> php-src ext/dom/php_dom.stub.php */
    public array $paramNames = ['source', 'options', 'overrideEncoding'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomHtmlDocumentCreateFromString::invoke($context, ...$args);
    }
}
