<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_convert_case() — multibyte case conversion (php-src ext/mbstring/mbstring.c; #7014).
 */
final class mb_convert_case extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_convert_case');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_convert_case() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $source = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_convert_case',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $mode = VmMbstring::coerceModeArg($frame->calledArgs[1], 'mb_convert_case', 1);
        $encoding = 2 === $argc
            ? MbstringState::internalEncoding()
            : VmMbstring::coerceMbEncodingNameArg($frame->calledArgs[2], 'mb_convert_case', 2);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(
                VmMbstring::convertCase($source, $mode, $encoding, 'mb_convert_case', 2)
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('mb_convert_case() requires two or three arguments');
        }

        $folded = JitMbConvertCase::tryCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        return JitMbConvertCase::lowerRuntime($context, $args);
    }
}
