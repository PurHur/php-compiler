<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomHtmlDocumentCreateFromFile;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Dom\HTMLDocument::createFromFile() — user-script AOT (leftover of #27300).
 *
 * Avoids ExternalMethod silent NULL on thin standalone AOT.
 */
final class DomHtmlDocumentCreateFromFile implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'Dom\\HTMLDocument::createFromFile';

    /** @var list<string> php-src ext/dom/php_dom.stub.php */
    public array $paramNames = ['path', 'options', 'overrideEncoding'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomHtmlDocumentCreateFromFile::invoke($context, ...$args);
    }
}
