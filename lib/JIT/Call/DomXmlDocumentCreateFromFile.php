<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomXmlDocumentCreateFromFile;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Dom\XMLDocument::createFromFile() — user-script AOT (#35804, leftover of #27108).
 *
 * php-src: ext/dom/xml_document.c
 *
 * Avoids ExternalMethod silent NULL on thin standalone AOT.
 */
final class DomXmlDocumentCreateFromFile implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'Dom\\XMLDocument::createFromFile';

    /** @var list<string> php-src ext/dom/php_dom.stub.php */
    public array $paramNames = ['path', 'options', 'overrideEncoding'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomXmlDocumentCreateFromFile::invoke($context, ...$args);
    }
}
