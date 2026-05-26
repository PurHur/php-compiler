<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** ini_get() — VM + JIT subset matching ini_set() keys (issue #1374, #1492). */
final class ini_get_ extends Internal
{
    public function __construct()
    {
        parent::__construct('ini_get');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('ini_get() requires exactly one argument');
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $optionVar = $frame->calledArgs[0]->resolveIndirect();
        $result = VmIni::get($frame->vmContext, $optionVar->toString());
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('ini_get() requires exactly one argument');
        }
        $optionStr = $this->jitString($context, $args[0], 'ini_get() option');

        return JitIni::get($context, $optionStr);
    }
}
