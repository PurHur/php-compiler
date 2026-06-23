<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ResourceSupport;
use PHPLLVM\Value;

/** stream_filter_remove() — detach a filter from a stream (#6040, ext/standard/streams.c). */
final class stream_filter_remove extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_filter_remove');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'stream_filter_remove() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        VmStreamArg::rejectEnumCaseOperand($v, 'stream_filter_remove', 1, 'stream_filter');
        $filterId = ResourceSupport::resolveHandle($v);
        if (
            null === $filterId
            || !ResourceSupport::isStreamFilterResource($v)
            || !VmStreamFilterChain::isValidFilter($filterId)
        ) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmStreamFilterChain::remove($filterId));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamFilter::remove($context, ...$args);
    }
}
