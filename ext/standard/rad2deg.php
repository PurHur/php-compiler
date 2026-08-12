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
        // php-src ext/standard/math.c — ArgumentCountError (#30534).
        $this->requireExactArgCount($frame, 'rad2deg', 1);
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
        // Catchable ArgumentCountError (AOT/JIT) — #30534.
        if (!$this->requireExactJitArgCount($context, $args, 'rad2deg', 1)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        $asFloat = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'rad2deg', 'float');

        return MathRad2deg::invoke($context, $asFloat);
    }

}
