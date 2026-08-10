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
 * timezone_name_get() — procedural DateTimeZone name (ext/date/php_date.c, #11746).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_name_get)
 */
final class timezone_name_get extends Internal
{
    public function __construct()
    {
        parent::__construct('timezone_name_get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('timezone_name_get() expects exactly 1 argument, %d given', $argc)
            );
        }
        $zone = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'timezone_name_get()',
            1,
            'object'
        );
        $name = DateTimeSupport::timezoneName($zone);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($name): void {
            $ret->string($name);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitTimezoneNameGet::invoke($context, ...$args);
    }
}
