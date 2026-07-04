<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * date_diff() — DateInterval between two DateTime objects (ext/date/php_date.c, #4604).
 */
final class date_diff extends Internal
{
    public function __construct()
    {
        parent::__construct('date_diff');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'date_diff() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $base = DateTimeSupport::requireDateTime(
            $frame->calledArgs[0],
            'date_diff()',
            1,
            'object',
            $frame->vmContext
        );
        $target = DateTimeSupport::requireDateTime(
            $frame->calledArgs[1],
            'date_diff()',
            2,
            'object',
            $frame->vmContext
        );
        $absolute = false;
        if ($argc >= 3) {
            $absolute = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'date_diff',
                3,
                'absolute'
            );
        }
        $interval = DateTimeSupport::diffDateTimes($base, $target, $absolute, $frame->vmContext);
        $frame->returnVar->object($interval);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateMutation::invokeDiff($context, ...$args);
    }
}
