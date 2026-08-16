<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** stream_filter_append() — attach a filter to a stream (#3283, ext/standard/streams.c). */
final class stream_filter_append extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_filter_append');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'stream_filter_append() expects at least 2 arguments, '.\max(0, $argc - 2).' given'
            );
        }
        $stream = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_filter_append',
            1
        );
        // Z_PARAM_STR $filter_name — TypeError under declare(strict_types=1) (#31408).
        $filterName = InternalStrictArg::resolveCoercibleStringArg(
            $frame,
            1,
            'stream_filter_append',
            'filter_name'
        );
        $readWrite = VmStreamFilterChain::ALL;
        if ($argc >= 3) {
            $readWrite = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'stream_filter_append',
                3,
                'mode'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filterId = VmStreamFilterChain::append($stream, $filterName, $readWrite, $frame);
        if (false === $filterId) {
            $frame->returnVar->bool(false);

            return;
        }
        VmStreamFilterChain::filterHandle($frame->returnVar, $filterId, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamFilter::append($context, ...$args);
    }
}
