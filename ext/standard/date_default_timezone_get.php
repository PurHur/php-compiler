<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPLLVM\Value;

/** date_default_timezone_get() — default timezone identifier (ext/date/php_date.c, #3292). */
final class date_default_timezone_get extends Internal
{
    public function __construct()
    {
        parent::__construct('date_default_timezone_get');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'date_default_timezone_get() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $tz = VmDate::defaultTimezoneGet();
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($tz): void {
            $ret->string($tz);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'date_default_timezone_get() is not implemented for JIT in this compiler build (issue #3292)'
        );
    }
}
