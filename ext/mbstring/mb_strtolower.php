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
 * mb_strtolower() — multibyte lower case (php-src ext/mbstring/mbstring.c; #3239).
 */
final class mb_strtolower extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strtolower');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_strtolower() expects at least 1 argument, %d given',
                $argc
            ));
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_strtolower',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = $argc >= 2
            ? VmMbstring::coerceMbEncodingNameArg($frame->calledArgs[1], 'mb_strtolower', 1)
            : MbstringState::internalEncoding();
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmMbstring::strtolower($string, $encoding))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_strtolower() is not lowered for JIT/AOT in this compiler build');
    }
}
