<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\random\JitRandomizerGetBytesFromString;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Random\Randomizer::getBytesFromString() — user-script AOT (#19574). */
final class RandomizerGetBytesFromString implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitRandomizerGetBytesFromString::invoke($context, ...$args);
    }
}
