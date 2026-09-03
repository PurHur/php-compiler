<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Random\Randomizer::getBytesFromString() — user-script AOT (#19574).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\random} (#36204). php-src: ext/random/randomizer.c.
 */
final class RandomizerGetBytesFromString implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireRandom()->randomizerGetBytesFromString($context, ...$args);
    }
}
