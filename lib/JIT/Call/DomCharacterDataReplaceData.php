<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomReplaceData;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMCharacterData::replaceData() — user-script AOT (php-src replace_data) (#32392). */
final class DomCharacterDataReplaceData implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomReplaceData::invoke($context, ...$args);
    }
}
