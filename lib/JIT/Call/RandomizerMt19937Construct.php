<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\random\JitRandomizerMt19937Construct;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Random\Engine\Mt19937::__construct() — user-script AOT (#19574). */
final class RandomizerMt19937Construct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitRandomizerMt19937Construct::invoke($context, ...$args);
    }
}
