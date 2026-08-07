<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** quoted_printable_decode() — MIME quoted-printable (php-src ext/standard/quot_print.c). */
final class quoted_printable_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('quoted_printable_decode');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28313).
        $this->requireExactArgCount($frame, 'quoted_printable_decode', 1);
        $data = VmString::trimFamilyStringArgForFrame($frame, 0, 'quoted_printable_decode', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::quoted_printable_decode($data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT try/catch) — peer basename #28286 / #28313.
        if (1 !== \count($args)) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('quoted_printable_decode() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
        }

        return JitQuotedPrintableDecode::decode(
            $context,
            $args[0],
            static fn (Context $ctx): Value => self::jitStringArg($ctx, $args[0])
        );
    }

    /** Soft-null — coerce+deprecate on forward profile (#21180, ext/standard/quot_print.c). */
    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'quoted_printable_decode',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'quoted_printable_decode',
            0,
            'string'
        );
    }
}
