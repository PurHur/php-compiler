<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * eval() — dynamic PHP execution in caller scope (#3358, JIT/AOT inline lowering #4652).
 */
final class eval_ extends Internal
{
    public function __construct()
    {
        parent::__construct('eval');
    }

    public function execute(Frame $frame): void
    {
        $result = VmEval::evalString($frame);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('eval() requires at least one argument');
        }
        if (\count($args) > 1) {
            throw new \LogicException('eval() takes exactly one argument');
        }

        return JitEval::invoke($context, $args[0]);
    }
}
