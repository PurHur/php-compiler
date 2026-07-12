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
        $data = VmString::stringBuiltinArgForFrame($frame, 0, 'quoted_printable_decode', 0, 'string');
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
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'quoted_printable_decode', 0, 'string'),
            $args[0]
        );
    }
}
