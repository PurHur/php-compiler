<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
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
        if (null === $frame->vmContext) {
            return;
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'ini_get', 'option', 0, $frame);
        $option = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'ini_get', 0, 'option');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIni::get($frame->vmContext, $option);
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
        JitInternalStrictArg::rejectNullString($context, $args[0], 'ini_get', 'option', 1);
        $optionStr = JitStringBuiltinArg::lower($context, $args[0], 'ini_get', 0, 'option');

        return JitIni::get($context, $optionStr);
    }
}
