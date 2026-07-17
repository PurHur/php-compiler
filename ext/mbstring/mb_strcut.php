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
 * mb_strcut() — byte-safe multibyte string cut (php-src ext/mbstring/mbstring.c; #4573).
 */
final class mb_strcut extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strcut');
    }

    public function execute(Frame $frame): void
    {
        if (!isset($frame->calledArgs[0]) || !isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError(sprintf(
                'mb_strcut() expects at least 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (isset($frame->calledArgs[4])) {
            throw new \ArgumentCountError(sprintf(
                'mb_strcut() expects at most 4 arguments, %d given',
                max(\array_keys($frame->calledArgs)) + 1
            ));
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#20225, mbstring.c).
        $string = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'mb_strcut', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $start = VmMbstring::coerceStartArg($frame, 'mb_strcut', 1);
        $length = null;
        if (isset($frame->calledArgs[2])) {
            $length = VmMbstring::coerceOptionalLengthArg($frame, 'mb_strcut', 2);
        }
        $encoding = isset($frame->calledArgs[3])
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'mb_strcut', 3)
            : 'UTF-8';
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(
                VmMbstring::strcut($string, $start, $length, $encoding)
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbStrcut::invoke($context, ...$args);
    }
}
