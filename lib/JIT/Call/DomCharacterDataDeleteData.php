<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomDeleteData;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMCharacterData::deleteData() — user-script AOT (php-src xmlUTF8Strsub) (#32389). */
final class DomCharacterDataDeleteData implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomDeleteData::invoke($context, ...$args);
    }
}
