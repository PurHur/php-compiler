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
 * mb_substr() — multibyte substring (php-src ext/mbstring/mbstring.c; #3239).
 */
final class mb_substr extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_substr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'mb_substr() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_substr',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $start = VmMbstring::coerceStartArg($frame, 'mb_substr', 1);
        $length = null;
        if ($argc >= 3) {
            $length = VmMbstring::coerceOptionalLengthArg($frame, 'mb_substr', 2);
        }
        $encoding = $argc >= 4
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'mb_substr', 3)
            : 'UTF-8';
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(
                VmMbstring::substr($string, $start, $length, $encoding)
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_substr() is not lowered for JIT/AOT in this compiler build');
    }
}
