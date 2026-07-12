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
 * mb_ereg_replace() — multibyte regex replace (php-src ext/mbstring/php_mbregex.c; #4635).
 */
final class mb_ereg_replace extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg_replace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_replace() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        $pattern = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_ereg_replace',
            0,
            'pattern'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $replacement = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'mb_ereg_replace',
            1,
            'replacement'
        );
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[2],
            'mb_ereg_replace',
            2,
            'string'
        );
        if (4 === $argc) {
            VmString::coerceStringBuiltinArg(
                $frame->calledArgs[3],
                'mb_ereg_replace',
                3,
                'options'
            );
        }

        $result = VmMbstring::eregReplace($pattern, $replacement, $string, false);
        if (false === $result && null !== VmMbstring::mbEregRegexCompileError($pattern, false)) {
            VmMbstring::warnMbEregRegexFailure($frame, 'mb_ereg_replace', $pattern, false);
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_ereg_replace() is not lowered for JIT/AOT in this compiler build');
    }
}
