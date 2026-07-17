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
 * mb_ereg_replace_callback() — multibyte regex replace via callable (php-src php_mbregex.c; #20024).
 */
final class mb_ereg_replace_callback extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg_replace_callback');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_replace_callback() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->vmContext) {
            throw new \LogicException(
                'mb_ereg_replace_callback() requires VM context in this compiler build'
            );
        }
        $pattern = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_ereg_replace_callback',
            0,
            'pattern'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[2],
            'mb_ereg_replace_callback',
            2,
            'string'
        );
        $options = null;
        if (isset($frame->calledArgs[3])) {
            $options = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[3],
                'mb_ereg_replace_callback',
                3,
                'options'
            );
        }

        $result = VmMbstring::eregReplaceCallback(
            $frame->vmContext,
            $pattern,
            $callback,
            $string,
            $options
        );
        if ((false === $result || null === $result)
            && null !== VmMbstring::mbEregRegexCompileError(
                $pattern,
                VmMbstring::optionsImplyIgnoreCase($options)
            )) {
            VmMbstring::warnMbEregRegexFailure(
                $frame,
                'mb_ereg_replace_callback',
                $pattern,
                VmMbstring::optionsImplyIgnoreCase($options)
            );
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
        throw new \LogicException(
            'mb_ereg_replace_callback() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
