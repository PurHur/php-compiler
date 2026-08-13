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
 * mb_ereg_search_init() — set search string/pattern cursor (php-src php_mbregex.c; #20024, #30781 AOT).
 */
final class mb_ereg_search_init extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg_search_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_init() expects at least 1 argument, %d given',
                $argc
            ));
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_ereg_search_init',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pattern = null;
        if (isset($frame->calledArgs[1])) {
            $pattern = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[1],
                'mb_ereg_search_init',
                1,
                'pattern'
            );
        }
        $options = null;
        if (isset($frame->calledArgs[2])) {
            $options = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[2],
                'mb_ereg_search_init',
                2,
                'options'
            );
        }

        $ok = VmMbstring::eregSearchInit($string, $pattern, $options);
        if (!$ok && null !== $pattern
            && null !== VmMbstring::mbEregRegexCompileError(
                $pattern,
                VmMbstring::optionsImplyIgnoreCase($options)
            )) {
            VmMbstring::warnMbEregRegexFailure(
                $frame,
                'mb_ereg_search_init',
                $pattern,
                VmMbstring::optionsImplyIgnoreCase($options)
            );
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbEregSearch::foldSearchInit($context, $args);
    }
}
