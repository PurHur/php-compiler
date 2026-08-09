<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_str_pad() — multibyte-aware padding (php-src ext/mbstring/mbstring.c; #6081).
 */
final class mb_str_pad extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_str_pad');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \ArgumentCountError(sprintf(
                'mb_str_pad() expects between 2 and 5 arguments, %d given',
                $argc
            ));
        }
        // Zend 8.4 ZPP soft-null + DEP (not TypeError) — #24176, reverts #19184/#22373.
        $input = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_str_pad', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $padLength = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'mb_str_pad',
            2,
            'length'
        );
        $padString = ' ';
        if ($argc >= 3) {
            $padString = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'mb_str_pad',
                2,
                'pad_string'
            );
        }
        $padType = 1;
        if ($argc >= 4) {
            $padType = VmString::resolveStrPadTypeArg(
                $frame->calledArgs[3],
                $frame,
                'mb_str_pad'
            );
        }
        $encoding = $argc >= 5
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[4], 'mb_str_pad', 4)
            : 'UTF-8';
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(
                VmMbstring::strPad($input, $padLength, $padString, $padType, $encoding)
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbStrPad::pad($context, ...$args);
    }
}
