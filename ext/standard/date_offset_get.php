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
 * date_offset_get() — procedural DateTime offset wrapper (ext/date/php_date.c, #11876).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_offset_get)
 */
final class date_offset_get extends Internal
{
    public function __construct()
    {
        parent::__construct('date_offset_get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('date_offset_get() expects exactly 1 argument, %d given', $argc)
            );
        }
        // Bare function label + argNum — DateTimeSupport formats Argument #N (#29864).
        $datetime = DateTimeSupport::requireDateTimeInterface(
            $frame->calledArgs[0],
            'date_offset_get()',
            $frame->vmContext,
            1,
            'object'
        );
        $offset = DateTimeSupport::dateOffsetGet($datetime);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($offset): void {
            $ret->int($offset);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateOffsetGet::invoke($context, ...$args);
    }
}
