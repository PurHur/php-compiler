<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mb_get_info() — mbstring runtime state dump (php-src ext/mbstring/mbstring.c; #20014). */
final class mb_get_info extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_get_info');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_get_info() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $type = 0 === $argc
            ? 'all'
            : VmMbstring::coerceGetInfoTypeArg($frame->calledArgs[0], 'mb_get_info', 0);
        VmMbstring::assignGetInfoResult($frame->returnVar, MbstringState::getInfo($type));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_get_info() JIT is not supported in this compiler build'
        );
    }
}
