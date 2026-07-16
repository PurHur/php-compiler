<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * strtotime() — parse natural-language date strings to Unix timestamps (#10742).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(strtotime)
 */
final class strtotime extends Internal
{
    public function __construct()
    {
        parent::__construct('strtotime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'strtotime() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#19651, ext/date/php_date.c)
        $time = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'strtotime', 0, 'datetime');
        $now = null;
        if (2 === $argc) {
            $baseVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $baseVar->type) {
                $now = VmDate::coerceNullableTimestampArgForFrame($frame, 1, 'strtotime', 2, 'baseTimestamp');
            }
        }
        $result = VmDateTimeNative::strtotime($time, $now);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStrtotime::invoke($context, ...$args);
    }
}
