<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** function_exists() — whether a function is registered in this compile unit (issue #1216). */
final class function_exists extends Internal
{
    public function __construct()
    {
        parent::__construct('function_exists');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('function_exists() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'function_exists', 'function', 0, $frame);
        $name = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'function_exists', 0, 'function');
        $frame->returnVar->bool(VmReflection::functionExists($ctx, $name));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('function_exists() requires exactly one argument');
        }
        JitInternalStrictArg::rejectNullString($context, $args[0], 'function_exists', 'function', 1);

        return JitFunctionExists::invoke($context, $args[0]);
    }
}
