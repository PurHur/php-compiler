<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xmlwriter\JitXmlWriterMethod;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** XMLWriter::toUri() — user-script AOT static factory (#19606 leftover of #35872). */
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
        return JitXmlWriterMethod::invoke($context, 'touri', ...$args);
    }
}
