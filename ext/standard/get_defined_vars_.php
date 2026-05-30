<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ScopeBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** get_defined_vars() — snapshot of caller locals (issue #3135). */
final class get_defined_vars_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_defined_vars');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('get_defined_vars() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmScope::getDefinedVars($frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('get_defined_vars() takes no arguments');
        }

        return ScopeBuiltinHelper::getDefinedVars($context);
    }
}
