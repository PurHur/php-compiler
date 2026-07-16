<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for Random\Randomizer::getBytesFromString() — user-script AOT (#19574). */
final class JitRandomizerGetBytesFromString
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $result = JitRandomizerUserScript::tryGetBytesFromString($context, ...$args);
        if (null === $result) {
            throw new \LogicException(
                'Random\\Randomizer::getBytesFromString() user-script AOT requires compile-time'
                .' Randomizer + string/length literals (#19574)'
            );
        }

        return $result;
    }
}
