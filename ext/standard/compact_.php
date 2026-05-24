<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ScopeBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** compact() — build an array from caller local variables (literal name list; VM + JIT). */
final class compact_ extends Internal
{
    public function __construct()
    {
        parent::__construct('compact');
    }

    public function execute(Frame $frame): void
    {
        if (0 === \count($frame->calledArgs)) {
            throw new \LogicException('compact() requires at least one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            VmScope::compact($frame);

            return;
        }
        $frame->returnVar->array(VmScope::compact($frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 === \count($args)) {
            throw new \LogicException('compact() requires at least one argument in this compiler build');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'compact() variable name #'.((int) $i + 1));
            }
        }
        return \call_user_func_array([ScopeBuiltinHelper::class, 'compact'], array_merge([$context], $args));
    }
}
