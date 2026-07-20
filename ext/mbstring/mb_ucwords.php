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
 * mb_ucwords() — multibyte title-case words (not shipped by Zend; kept unregistered, #21458).
 *
 * Historical forward-profile experiment (#20799). Zend uses mb_convert_case(..., MB_CASE_TITLE).
 */
final class mb_ucwords extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ucwords');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_ucwords() expects at least 1 argument, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#19433, mbstring.c).
        $string = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'mb_ucwords', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = $argc >= 2
            ? VmMbstring::coerceMbEncodingNameArg($frame->calledArgs[1], 'mb_ucwords', 1)
            : MbstringState::internalEncoding();
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmMbstring::ucwords($string, $encoding))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_ucwords() is not lowered for JIT/AOT in this compiler build');
    }
}
