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
 * mb_ereg_search_getpos() — current search byte offset (php-src php_mbregex.c; #20024).
 */
final class mb_ereg_search_getpos extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg_search_getpos');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_getpos() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $pos = MbstringState::searchPos();
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($pos): void {
            $ret->int($pos);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_ereg_search_getpos() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
