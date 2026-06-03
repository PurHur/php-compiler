<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Generator::current(): mixed — JIT (#4558, Zend/zend_generators.c). */
final class GeneratorCurrent implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Generator::current() called without $this');
        }
        $genVar = $args[0];
        GeneratorHelper::ensureStarted($context, $genVar);
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);

        return GeneratorHelper::boxCurrentOrNull($context, $statePtr);
    }
}
