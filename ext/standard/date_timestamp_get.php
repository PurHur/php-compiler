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
 * date_timestamp_get() — procedural DateTimeInterface::getTimestamp wrapper (ext/date/php_date.c, #9219).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_timestamp_get)
 */
final class date_timestamp_get extends Internal
{
    public function __construct()
    {
        parent::__construct('date_timestamp_get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('date_timestamp_get() expects exactly 1 argument, %d given', $argc)
            );
        }
        // Bare function label + argNum — DateTimeSupport formats Argument #N (#29877).
        $datetime = DateTimeSupport::requireDateTimeInterface(
            $frame->calledArgs[0],
            'date_timestamp_get()',
            $frame->vmContext,
            1,
            'object'
        );
        $timestamp = DateTimeSupport::getTimestamp($datetime);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($timestamp): void {
            $ret->int($timestamp);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('date_timestamp_get() is VM-only in this compiler build (issue #9219)');
    }
}
