<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * timezone_offset_get() — procedural DateTimeZone offset (ext/date/php_date.c, #6041).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_offset_get)
 */
final class timezone_offset_get extends Internal
{
    public function __construct()
    {
        parent::__construct('timezone_offset_get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('timezone_offset_get() expects exactly 2 arguments, %d given', $argc)
            );
        }
        $zone = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'timezone_offset_get()',
            1,
            'object'
        );
        $datetime = DateTimeSupport::requireDateTimeInterface(
            $frame->calledArgs[1],
            'timezone_offset_get(): Argument #2 ($datetime)',
            $frame->vmContext
        );
        $offset = DateTimeSupport::timezoneOffset($zone, $datetime);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($offset): void {
            $ret->int($offset);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitTimezoneOffsetGet::invoke($context, ...$args);
    }
}
