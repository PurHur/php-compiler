<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xmlwriter\JitXmlWriterUserScript;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * XMLWriter::toMemory() — user-script AOT static factory (#35890 / #19606).
 *
 * Host PHP 8.2 has no toMemory; fold as new XMLWriter + openMemory (peer openMemory #19551).
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toMemory
 */
final class XmlWriterToMemory implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'XMLWriter::toMemory';

    /** @var list<string> php-src ext/xmlwriter/php_xmlwriter.stub.php */
    public array $paramNames = [];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        $result = JitXmlWriterUserScript::tryToMemory($context, ...$args);
        if (null === $result) {
            throw new \LogicException(
                'XMLWriter::toMemory() user-script AOT requires host xmlwriter (#35890)'
            );
        }

        return $result;
    }
}
