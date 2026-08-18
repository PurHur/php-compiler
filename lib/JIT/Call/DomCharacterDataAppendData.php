<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAppendData;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMCharacterData::appendData() — user-script AOT (php-src xmlTextConcat) (#32376). */
final class DomCharacterDataAppendData implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomAppendData::invoke($context, ...$args);
    }
}
