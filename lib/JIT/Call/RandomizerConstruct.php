<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\random\JitRandomizerConstruct;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Random\Randomizer::__construct() — user-script AOT (#19574). */
final class RandomizerConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitRandomizerConstruct::invoke($context, ...$args);
    }
}
