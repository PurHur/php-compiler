<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_strimwidth() — truncate to display width (php-src ext/mbstring/mbstring.c; #3495).
 */
final class mb_strimwidth extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strimwidth');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(sprintf(
                'mb_strimwidth() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_strimwidth',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $from = VmMbstring::coerceStartArg($frame, 'mb_strimwidth', 1);
        $width = VmMbstring::coerceLengthArg($frame, 'mb_strimwidth', 2);
        $trimmarker = $argc >= 4
            ? VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'mb_strimwidth', 3, 'trimmarker')
            : '';
        $encoding = $argc >= 5
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[4], 'mb_strimwidth', 4)
            : 'UTF-8';
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(
                VmMbstring::strimwidth($string, $from, $width, $trimmarker, $encoding)
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbStrwidth::strimwidth($context, ...$args);
    }
}
