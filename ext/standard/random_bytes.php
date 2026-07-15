<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * random_bytes() — CSPRNG via OS (VM: VmRandomNative/VmRandomPure; JIT/AOT: RandomBytesJitHelper PHP).
 */
final class random_bytes extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('random_bytes() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $length = VmMath::parseZParamLongBuiltinArgForFrame($frame, 0, 'random_bytes', 1, 'length');
        $frame->returnVar->string(VmString::randomBytes($length));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('random_bytes() requires exactly one argument');
        }
        $length = JitRandomBytesArg::lowerLength($context, $args[0]);

        return JitRandomBytes::generate($context, $length);
    }
}
