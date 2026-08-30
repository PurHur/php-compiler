<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xmlwriter\JitXmlWriterUserScript;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * XMLWriter::toUri() — user-script AOT static factory (#35890 / #19606).
 *
 * Host PHP 8.2 has no toUri; fold as new XMLWriter + openUri (peer openUri #35872).
 * URI side-effect is applied at compile time (same as openUri).
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toUri
 */
final class XmlWriterToUri implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'XMLWriter::toUri';

    /** @var list<string> php-src ext/xmlwriter/php_xmlwriter.stub.php */
    public array $paramNames = ['uri'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        $result = JitXmlWriterUserScript::tryToUri($context, ...$args);
        if (null === $result) {
            throw new \LogicException(
                'XMLWriter::toUri() user-script AOT requires compile-time URI literal (#35890)'
            );
        }

        return $result;
    }
}
