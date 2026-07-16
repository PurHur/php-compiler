<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for Random\Engine\Mt19937::__construct() — user-script AOT (#19574). */
final class JitRandomizerMt19937Construct
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $stored = JitRandomizerUserScript::tryMt19937Construct($context, ...$args);
        if (null === $stored) {
            throw new \LogicException(
                'Random\\Engine\\Mt19937::__construct() user-script AOT requires a compile-time seed (#19574)'
            );
        }

        return $stored;
    }
}
