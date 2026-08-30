<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xmlreader\JitXmlReaderMethod;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** XMLReader::fromStream() — user-script AOT (#35900 leftover of #27299). */
final class XmlReaderFromStream implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'XMLReader::fromStream';

    /** @var list<string> php-src ext/xmlreader/php_xmlreader.stub.php */
    public array $paramNames = ['stream', 'encoding', 'flags', 'documentUri'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitXmlReaderMethod::invoke($context, 'fromstream', ...$args);
    }
}
