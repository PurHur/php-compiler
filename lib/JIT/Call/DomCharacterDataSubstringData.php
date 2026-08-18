<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomSubstringData;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMCharacterData::substringData() — user-script AOT (php-src characterdata.c) (#32372). */
final class DomCharacterDataSubstringData implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomSubstringData::invoke($context, ...$args);
    }
}
