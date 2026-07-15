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
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('quoted_printable_encode() requires exactly one argument in this compiler build');
        }
        $data = self::vmStringArg($frame, 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::quoted_printable_encode($data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('quoted_printable_encode() requires exactly one argument in this compiler build');
        }
        return JitQuotedPrintableEncode::encode(
            $context,
            JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[0],
                'quoted_printable_encode',
                0,
                'string'
            ),
            $args[0]
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (null !== $frame->parent && $frame->parent->block->strictTypes) {
            return InternalStrictArg::requireString(
                $frame,
                $argIndex,
                'quoted_printable_encode',
                $paramName
            )->toString();
        }

        return VmString::coerceStringBuiltinArg(
            $frame->calledArgs[$argIndex],
            'quoted_printable_encode',
            $argIndex,
            $paramName
        );
    }
}
