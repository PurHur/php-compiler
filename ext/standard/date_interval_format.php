<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateIntervalSupport;
use PHPLLVM\Value;

/**
 * date_interval_format() — procedural DateInterval::format() alias (#7278, ext/date/php_date.c).
 */
final class date_interval_format extends Internal
{
    public function __construct()
    {
        parent::__construct('date_interval_format');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'date_interval_format() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $interval = DateIntervalSupport::requireDateInterval(
            $frame->calledArgs[0],
            'date_interval_format',
            1,
            'object'
        );
        $format = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'date_interval_format',
            2,
            'format'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(DateIntervalSupport::format($interval, $format));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \LogicException('date_interval_format() expects exactly 2 arguments in this compiler build');
        }

        return JitDateIntervalFormat::invoke($context, $args[0], $args[1]);
    }
}
