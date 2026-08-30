<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xmlwriter\JitXmlWriterMethod;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * XMLWriter::toStream() — user-script AOT static factory (#35895 / #19606).
 *
 * Host PHP 8.2 has no toStream; fold via new XMLWriter + openUri when the
 * stream was opened from a compile-time fopen path (peer toUri #35890).
 * php-src: zim_XMLWriter_toStream
 */
final class XmlWriterToStream implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'XMLWriter::toStream';

    /** @var list<string> php-src ext/xmlwriter/php_xmlwriter.stub.php */
    public array $paramNames = ['stream'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitXmlWriterMethod::invoke($context, 'tostream', ...$args);
    }
}
