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
 * mb_ereg_search_pos() — search and return [offset, length] (php-src php_mbregex.c; #20024).
 */
final class mb_ereg_search_pos extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg_search_pos');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_pos() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pattern = null;
        if (isset($frame->calledArgs[0])) {
            $pattern = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[0],
                'mb_ereg_search_pos',
                0,
                'pattern'
            );
        }
        $options = null;
        if (isset($frame->calledArgs[1])) {
            $options = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[1],
                'mb_ereg_search_pos',
                1,
                'options'
            );
        }

        $out = VmMbstring::eregSearchExec(1, $pattern, $options);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($out): void {
            if (false === $out) {
                $ret->bool(false);

                return;
            }
            /** @var array{0: int, 1: int} $out */
            $ret->array(VmMbstring::searchPosPairToHashTable($out));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_ereg_search_pos() is not lowered for JIT/AOT in this compiler build');
    }
}
