<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureBindHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Closure::bind() static method — JIT (#4192). */
final class ClosureBind implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('Closure::bind() expects at least 2 arguments');
        }
        $closure = $args[0];
        $newThis = $args[1];
        $newScope = $args[2] ?? null;
        $result = ClosureBindHelper::bind(
            $context,
            $closure,
            $newThis,
            $newScope,
            'Closure::bind()'
        );

        return ClosureBindHelper::boxReturn($context, $result);
    }
}
