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
 * date_get_last_errors() — procedural DateTime::getLastErrors wrapper (ext/date/php_date.c, #9219, #30749).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_get_last_errors)
 */
final class date_get_last_errors extends Internal
{
    public function __construct()
    {
        parent::__construct('date_get_last_errors');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('date_get_last_errors() expects exactly 0 arguments, %d given', $argc)
            );
        }
        BuiltinExecute::writeReturn($frame, static function ($ret): void {
            DateTimeSupport::writeCreateFromFormatLastErrors($ret);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateGetLastErrors::invoke($context, ...$args);
    }
}
