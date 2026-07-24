<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** strptime() — parse date/time string to tm array (ext/standard/datetime.c, #3694). */
final class strptime extends Internal
{
    public function __construct()
    {
        parent::__construct('strptime');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'strptime() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        // php-src: ext/date/php_date.c — PHP_FUNCTION(strptime) deprecated since 8.1 (#22771).
        VmEngineBuiltinDeprecation::emitFunction($frame, 'strptime');
        if (null === $frame->returnVar) {
            return;
        }
        $date = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'strptime', 1, 'date');
        $format = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'strptime', 2, 'format');
        $parsed = VmDate::strptime($date, $format);
        if (false === $parsed) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($parsed);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStrptime::invoke($context, ...$args);
    }
}
