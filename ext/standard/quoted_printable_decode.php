<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
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
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('quoted_printable_decode() requires exactly one argument in this compiler build');
        }
        $data = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'quoted_printable_decode', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::quoted_printable_decode($data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('quoted_printable_decode() requires exactly one argument in this compiler build');
        }

        return JitQuotedPrintableDecode::decode(
            $context,
            $args[0],
            static fn (Context $ctx): Value => self::jitStringArg($ctx, $args[0])
        );
    }

    /** Z_PARAM_STR — null TypeError on 8.4 forward profile (#19283, ext/standard/quot_print.c). */
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

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'quoted_printable_decode',
            0,
            'string'
        );
    }
}
