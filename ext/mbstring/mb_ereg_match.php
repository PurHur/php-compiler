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
 * mb_ereg_match() — match at start of string (php-src ext/mbstring/php_mbregex.c; #20024).
 */
final class mb_ereg_match extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg_match');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_match() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $pattern = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_ereg_match',
            0,
            'pattern'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'mb_ereg_match',
            1,
            'string'
        );
        $options = null;
        if (isset($frame->calledArgs[2])) {
            $options = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[2],
                'mb_ereg_match',
                2,
                'options'
            );
        }

        $matched = VmMbstring::eregMatchAnchored($pattern, $string, $options);
        if (!$matched && null !== VmMbstring::mbEregRegexCompileError(
            $pattern,
            VmMbstring::optionsImplyIgnoreCase($options)
        )) {
            VmMbstring::warnMbEregRegexFailure(
                $frame,
                'mb_ereg_match',
                $pattern,
                VmMbstring::optionsImplyIgnoreCase($options)
            );
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($matched): void {
            $ret->bool($matched);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_ereg_match() is not lowered for JIT/AOT in this compiler build');
    }
}
