<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** func_get_arg() — positional argument from the current user function (issue #11614). */
final class func_get_arg extends Internal
{
    private const VALUE_ERROR_NEGATIVE = 'func_get_arg(): Argument #1 ($position) must be greater than or equal to 0';

    private const VALUE_ERROR_RANGE = 'func_get_arg(): Argument #1 ($position) must be less than the number of the arguments passed to the currently executed function';

    public function __construct()
    {
        parent::__construct('func_get_arg');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'func_get_arg() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $position = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'func_get_arg', 1, 'position');
        if ($position < 0) {
            throw new \ValueError(self::VALUE_ERROR_NEGATIVE);
        }
        try {
            $args = VmReflection::userCallArgs($frame);
        } catch (\LogicException) {
            throw new \Error('func_get_arg() cannot be called from the global scope');
        }
        if ($position >= \count($args)) {
            throw new \ValueError(self::VALUE_ERROR_RANGE);
        }
        $frame->returnVar->copyFrom($args[$position]);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('func_get_arg() expects exactly 1 argument in this compiler build');
        }

        return JitFuncArgs::getArg($context, $args[0]);
    }
}
