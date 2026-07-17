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
 * mb_ereg_search_setpos() — set search byte offset (php-src php_mbregex.c; #20024).
 */
final class mb_ereg_search_setpos extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg_search_setpos');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_setpos() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $offset = VmMbstring::coerceOffsetArg($frame, 'mb_ereg_search_setpos', 0);
        $ok = VmMbstring::eregSearchSetPos($offset);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_ereg_search_setpos() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
