<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xmlreader\JitXmlReaderMethod;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * XMLReader::fromUri() — user-script AOT leftover of fromString (#35900 / #27299).
 *
 * Host PHP 8.2 has no fromUri(); fold via open()/file_get_contents + tokenize.
 * php-src: ext/xmlreader/php_xmlreader.c zim_xmlreader_fromUri
 */
final class XmlReaderFromUri implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'XMLReader::fromUri';

    /** @var list<string> php-src ext/xmlreader/php_xmlreader.stub.php */
    public array $paramNames = ['uri', 'encoding', 'flags'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitXmlReaderMethod::invoke($context, 'fromuri', ...$args);
    }
}
