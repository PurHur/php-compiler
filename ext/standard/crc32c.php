<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** crc32c() — CRC32C (Castagnoli), signed 32-bit int (VM + JIT/AOT via ext/standard/VmCrc32c.php). */
final class crc32c extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('crc32c() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $subject = VmString::coerceTypedStringBuiltinArg(
            $frame->calledArgs[0],
            'crc32c',
            0,
            'string'
        );
        $frame->returnVar->int(VmCrc32c::compute($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('crc32c() requires exactly one argument in this compiler build');
        }
        $subject = JitCrc32c::lowerStringSubject($context, $args[0]);

        return JitCrc32c::compute($context, $subject);
    }
}
