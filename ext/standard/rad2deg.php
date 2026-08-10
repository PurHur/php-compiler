<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\MathRad2deg;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * rad2deg() for integer or float arguments (subset of PHP standard library).
 */
final class rad2deg extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('rad2deg() requires exactly one argument');
        }
        $num = VmMath::parseStrictFloatBuiltinArgForFrame(
            $frame,
            'rad2deg',
            1,
            'num'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::rad2deg($num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('rad2deg() requires exactly one argument');
        }
        $asFloat = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'rad2deg', 'float');

        return MathRad2deg::invoke($context, $asFloat);
    }

}
