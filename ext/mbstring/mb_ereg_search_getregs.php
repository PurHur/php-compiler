<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_ereg_search_getregs() — last search captures (php-src php_mbregex.c; #20024).
 */
final class mb_ereg_search_getregs extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg_search_getregs');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_getregs() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $regs = VmMbstring::eregSearchGetRegs();
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($regs): void {
            if (false === $regs) {
                $ret->bool(false);

                return;
            }
            $ret->array(VmMbstring::mbRegsToHashTable($regs));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_ereg_search_getregs() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
