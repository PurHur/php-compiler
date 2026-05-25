<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\VariableFunctionCallHelper;
use PHPLLVM\Value;

/**
 * Runtime-resolved $fn() when the callee name is not compile-time constant (issue #1997).
 */
final class RuntimeVariableFunction implements Call
{
    /**
     * @param list<string> $hintedNames Lowercase callee names from CFG (e.g. ?? 'strlen' default).
     */
    public function __construct(
        public readonly Variable $nameVar,
        public readonly array $hintedNames = [],
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return VariableFunctionCallHelper::dispatch($context, $this->nameVar, $this->hintedNames, ...$args);
    }
}
