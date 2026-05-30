<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * eval() — dynamic PHP execution in caller scope (VM-only; ext/standard/basic_functions.c parity, #3358).
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
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('eval() is VM-only in this compiler build');
    }
}
