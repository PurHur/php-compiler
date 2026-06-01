<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureBindHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Closure::bindTo() instance method — JIT (#4192). */
final class ClosureBindTo implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('Closure::bindTo() expects at least 2 arguments');
        }
        $receiver = $args[0];
        $newThis = $args[1];
        $newScope = $args[2] ?? null;
        $result = ClosureBindHelper::bind(
            $context,
            $receiver,
            $newThis,
            $newScope,
            'Closure::bindTo()'
        );

        return ClosureBindHelper::boxReturn($context, $result);
    }
}
