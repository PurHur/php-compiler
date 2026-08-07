<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** crc32() — CRC32B (IEEE), signed 32-bit int (VM + JIT/AOT via ext/standard/VmCrc32.php). */
final class crc32 extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/crc32.c — arity 1 only, no seed (#28313).
        $this->requireExactArgCount($frame, 'crc32', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $subject = self::vmStringArg($frame, 0);
        $frame->returnVar->int(VmCrc32::compute($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT try/catch) — peer basename #28286 / #28313.
        if (1 !== \count($args)) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('crc32() expects exactly 1 argument, %d given', \count($args))
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        // Non-strict null → E_DEPRECATED + '' (php-src crc32.c / #21181); strict_types TypeErrors.
        $subject = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'crc32', 0, 'string')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'crc32', 0, 'string');
        $seed = $context->getTypeFromString('int64')->constInt(0, false);

        return JitCrc32::compute($context, $subject, $seed);
    }

    private static function vmStringArg(Frame $frame, int $argIndex): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'crc32', 'string')->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'crc32',
            $argIndex,
            'string'
        );
    }
}
