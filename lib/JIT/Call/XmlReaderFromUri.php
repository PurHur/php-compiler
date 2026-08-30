<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xmlreader\JitXmlReaderMethod;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * XMLReader::fromUri() — user-script AOT static factory (#35900 leftover of #27299).
 *
 * Host PHP 8.2 has no fromUri; fold via compile-time URI + tokenize (peer fromString).
 * php-src: zim_xmlreader_fromUri
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
