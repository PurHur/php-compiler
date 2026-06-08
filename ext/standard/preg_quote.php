<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** preg_quote() — escape regex metacharacters (subset of PHP; native LLVM in JIT/AOT). */
final class preg_quote extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('preg_quote() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $subject = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'preg_quote',
            0,
            'str'
        );
        $delimiter = null;
        if (2 === $argc) {
            $delimiter = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'preg_quote',
                1,
                'delimiter'
            );
        }
        $frame->returnVar->string(VmString::pregQuote($subject, $delimiter));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('preg_quote() requires one or two arguments in this compiler build');
        }
        $subject = JitStringBuiltinArg::lower($context, $args[0], 'preg_quote', 0, 'str');
        if (1 === $argc) {
            return JitPregQuote::quote($context, $subject, null);
        }

        return JitPregQuote::quote(
            $context,
            $subject,
            JitStringBuiltinArg::lower($context, $args[1], 'preg_quote', 1, 'delimiter')
        );
    }
}
