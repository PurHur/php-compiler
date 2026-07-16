<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for Random\Randomizer::__construct() — user-script AOT (#19574). */
final class JitRandomizerConstruct
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $stored = JitRandomizerUserScript::tryRandomizerConstruct($context, ...$args);
        if (null === $stored) {
            throw new \LogicException(
                'Random\\Randomizer::__construct() user-script AOT requires a compile-time Mt19937 engine (#19574)'
            );
        }

        return $stored;
    }
}
