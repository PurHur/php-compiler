<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_supports() — VM via VmFs; JIT/AOT via __compiler_stream_supports (issue #5062, #16329). */
final class stream_supports extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_supports');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_supports() requires exactly two arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $featureVar = $frame->calledArgs[1]->resolveIndirect();
        if (!$handleVar->isStreamResource()) {
            throw new \TypeError(
                'stream_supports(): Argument #1 ($stream) must be of type resource, '
                . VmStreamArg::debugTypeName($handleVar) . ' given'
            );
        }

        $feature = VmStreamSupports::resolveFeatureVariable($featureVar);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $feature) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(
            VmFs::streamSupports($handleVar->toInt(), $feature)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('stream_supports() requires exactly two arguments in this compiler build');
        }

        return JitStreamSupports::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_supports() stream'),
                $context->getTypeFromString('int64')
            ),
            JitStreamSupportsFeatureArg::lower($context, $args[1])
        );
    }
}
