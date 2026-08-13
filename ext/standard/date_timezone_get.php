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
 * date_timezone_get() — procedural DateTimeInterface::getTimezone wrapper (ext/date/php_date.c, #9219, JIT/AOT #30746).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_timezone_get)
 */
final class date_timezone_get extends Internal
{
    public function __construct()
    {
        parent::__construct('date_timezone_get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('date_timezone_get() expects exactly 1 argument, %d given', $argc)
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('date_timezone_get() requires VM context');
        }
        $datetime = DateTimeSupport::requireDateTimeInterface(
            $frame->calledArgs[0],
            'date_timezone_get(): Argument #1 ($object)',
            $frame->vmContext,
            1,
            'object'
        );
        $zone = DateTimeSupport::getTimezoneObject($datetime, $frame->vmContext);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($zone): void {
            $ret->object($zone);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateMutation::invokeTimezoneGet($context, ...$args);
    }
}
