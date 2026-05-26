<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

final class ini_set_ extends Internal
{
    public function __construct()
    {
        parent::__construct('ini_set');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('ini_set() requires exactly two arguments');
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $optionVar = $frame->calledArgs[0]->resolveIndirect();
        $valueVar = $frame->calledArgs[1]->resolveIndirect();
        $result = VmIni::set($frame->vmContext, $optionVar->toString(), $valueVar->toString());
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('ini_set() requires exactly two arguments');
        }
        $optionStr = $this->jitString($context, $args[0], 'ini_set() option');
        $valueStr = $this->jitString($context, $args[1], 'ini_set() value');

        return JitIni::set($context, $optionStr, $valueStr);
    }
}
