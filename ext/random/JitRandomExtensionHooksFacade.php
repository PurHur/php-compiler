<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\RandomExtensionHooks;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * random surfaces for lib/JIT Call Randomizer* (#36204).
 *
 * php-src: ext/random/randomizer.c / engine_mt19937.c — Randomizer + Mt19937.
 * Registered from {@see Module::jitInit} so Call files do not import ext/random.
 */
final class JitRandomExtensionHooksFacade implements RandomExtensionHooks
{
    public function randomizerConstruct(Context $context, JITVariable ...$args): Value
    {
        return JitRandomizerConstruct::invoke($context, ...$args);
    }

    public function randomizerGetBytesFromString(Context $context, JITVariable ...$args): Value
    {
        return JitRandomizerGetBytesFromString::invoke($context, ...$args);
    }

    public function mt19937Construct(Context $context, JITVariable ...$args): Value
    {
        return JitRandomizerMt19937Construct::invoke($context, ...$args);
    }
}
