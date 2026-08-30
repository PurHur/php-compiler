<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xmlreader\JitXmlReaderMethod;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * XMLReader::open() — user-script AOT leftover of fromUri/fromString (#35907 / #27299).
 *
 * php-src: zim_xmlreader_open — static returns XMLReader|false; instance returns bool.
 */
final class XmlReaderOpen implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'XMLReader::open';

    /** @var list<string> php-src ext/xmlreader/php_xmlreader.stub.php */
    public array $paramNames = ['uri', 'encoding', 'flags'];

    /** Static call has no implicit $this; instance form keeps EX(This) separately. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitXmlReaderMethod::invoke($context, 'open', ...$args);
    }
}
