<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomXPathEvaluate;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMXPath::evaluate() — user-script AOT (#18526). */
final class DomXPathEvaluate implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomXPathEvaluate::invoke($context, ...$args);
    }
}
