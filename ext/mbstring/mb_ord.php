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
 * mb_ord() — multibyte character to codepoint (php-src ext/mbstring/mbstring.c; #4559).
 */
final class mb_ord extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ord');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_ord() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_ord',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = 1 === $argc
            ? MbstringState::internalEncoding()
            : VmMbstring::coerceEncodingArg($frame->calledArgs[1], 'mb_ord', 1);
        $result = VmMbstring::ord($string, $encoding);
        if (false === $result) {
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->bool(false));

            return;
        }
        BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->int($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_ord() is not lowered for JIT/AOT in this compiler build');
    }
}
