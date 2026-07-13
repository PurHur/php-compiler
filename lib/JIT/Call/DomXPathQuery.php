<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomXPathQuery;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMXPath::query() — user-script AOT (#18493). */
final class DomXPathQuery implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomXPathQuery::invoke($context, ...$args);
    }
}
