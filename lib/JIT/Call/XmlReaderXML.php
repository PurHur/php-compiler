<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xmlreader\JitXmlReaderMethod;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** XMLReader::XML() — user-script AOT in-memory open (#28670, re-#27299). */
final class XmlReaderXML implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'XMLReader::XML';

    /** @var list<string> php-src ext/xmlreader/php_xmlreader.stub.php */
    public array $paramNames = ['source', 'encoding', 'flags'];

    /** Static factory — no implicit $this (instance form keeps EX(This) separately). */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitXmlReaderMethod::invoke($context, 'xml', ...$args);
    }
}
