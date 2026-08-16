<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** stream_filter_prepend() — attach a filter at the head of a stream chain (#3283). */
final class stream_filter_prepend extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_filter_prepend');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'stream_filter_prepend() expects at least 2 arguments, '.\max(0, $argc - 2).' given'
            );
        }
        $stream = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_filter_prepend',
            1
        );
        // Z_PARAM_STR $filter_name — TypeError under declare(strict_types=1) (#31408).
        $filterName = InternalStrictArg::resolveCoercibleStringArg(
            $frame,
            1,
            'stream_filter_prepend',
            'filter_name'
        );
        $readWrite = VmStreamFilterChain::ALL;
        if ($argc >= 3) {
            $readWrite = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'stream_filter_prepend',
                3,
                'mode'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filterId = VmStreamFilterChain::prepend($stream, $filterName, $readWrite, $frame);
        if (false === $filterId) {
            $frame->returnVar->bool(false);

            return;
        }
        VmStreamFilterChain::filterHandle($frame->returnVar, $filterId, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamFilter::prepend($context, ...$args);
    }
}
