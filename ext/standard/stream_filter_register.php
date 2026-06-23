<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_filter_register() — register a named stream filter (#3283, ext/standard/streams.c). */
final class stream_filter_register extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_filter_register');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'stream_filter_register() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $filterName = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'stream_filter_register',
            0,
            'filtername'
        );
        $className = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'stream_filter_register',
            1,
            'classname'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmStreamFilters::register($filterName, $className));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamFilter::register($context, ...$args);
    }
}
