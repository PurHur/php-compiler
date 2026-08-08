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
        // php-src ext/standard/random.c — ArgumentCountError (#28476).
        $this->requireExactArgCount($frame, 'random_bytes', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $length = VmMath::parseZParamLongBuiltinArgForFrame($frame, 0, 'random_bytes', 1, 'length');
        $frame->returnVar->string(VmString::randomBytes($length));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28476.
        if (!$this->requireExactJitArgCount($context, $args, 'random_bytes', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $length = JitRandomBytesArg::lowerLength($context, $args[0]);

        return JitRandomBytes::generate($context, $length);
    }
}
