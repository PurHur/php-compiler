<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xmlwriter\JitXmlWriterMethod;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** XMLWriter instance methods — user-script AOT via host xmlwriter (#19551). */
final class XmlWriterMethod implements Call
{
    public function __construct(
        private readonly string $methodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitXmlWriterMethod::invoke($context, $this->methodLc, ...$args);
    }
}
