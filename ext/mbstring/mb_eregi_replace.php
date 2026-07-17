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
 * mb_eregi_replace() — case-insensitive multibyte regex replace (php-src php_mbregex.c; #20024).
 */
final class mb_eregi_replace extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_eregi_replace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'mb_eregi_replace() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        $pattern = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_eregi_replace',
            0,
            'pattern'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $replacement = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'mb_eregi_replace',
            1,
            'replacement'
        );
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[2],
            'mb_eregi_replace',
            2,
            'string'
        );
        $options = null;
        if (isset($frame->calledArgs[3])) {
            $options = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[3],
                'mb_eregi_replace',
                3,
                'options'
            );
        }

        $result = VmMbstring::eregReplace($pattern, $replacement, $string, true, $options);
        if ((false === $result || null === $result)
            && null !== VmMbstring::mbEregRegexCompileError($pattern, true)) {
            VmMbstring::warnMbEregRegexFailure($frame, 'mb_eregi_replace', $pattern, true);
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (null === $result) {
                $ret->null();

                return;
            }
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_eregi_replace() is not lowered for JIT/AOT in this compiler build');
    }
}
