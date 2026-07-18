<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\xsl\JitXsltMethod;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** XSLTProcessor security/EXSLT methods — user-script AOT via host ext/xsl (#20392). */
final class XsltMethod implements Call
{
    public function __construct(
        private readonly string $methodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitXsltMethod::invoke($context, $this->methodLc, ...$args);
    }
}
