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
 * timezone_location_get() — procedural DateTimeZone geo metadata (ext/date/php_date.c, #6041).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_location_get)
 */
final class timezone_location_get extends Internal
{
    public function __construct()
    {
        parent::__construct('timezone_location_get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('timezone_location_get() expects exactly 1 argument, %d given', $argc)
            );
        }
        $zone = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'timezone_location_get(): Argument #1 ($object)'
        );
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($zone): void {
            DateTimeSupport::timezoneLocationInto($zone, $ret);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitTimezoneLocationGet::invoke($context, ...$args);
    }
}
