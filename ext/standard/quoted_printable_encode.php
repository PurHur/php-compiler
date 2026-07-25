<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** quoted_printable_encode() — MIME quoted-printable (php-src ext/standard/quot_print.c). */
final class quoted_printable_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('quoted_printable_encode');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/quot_print.c — ArgumentCountError (#23164).
        $this->requireExactArgCount($frame, 'quoted_printable_encode', 1);
        $data = self::vmStringArg($frame, 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::quoted_printable_encode($data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'quoted_printable_encode', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        return JitQuotedPrintableEncode::encode(
            $context,
            $args[0],
            static fn (Context $ctx): Value => self::jitStringArg($ctx, $args[0])
        );
    }

    /** Soft-null — coerce+deprecate on forward profile (#21180, ext/standard/quot_print.c). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString(
                $frame,
                $argIndex,
                'quoted_printable_encode',
                $paramName
            )->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'quoted_printable_encode',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'quoted_printable_encode',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'quoted_printable_encode',
            0,
            'string'
        );
    }
}
