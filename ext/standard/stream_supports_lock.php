<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StreamIoRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** stream_supports_lock() — VM via VmFs; JIT/AOT via StreamIoJitHelper (#6039, #19462). */
final class stream_supports_lock extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_supports_lock');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_supports_lock() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'stream_supports_lock');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            VmFs::streamSupports($handle, VmStreamSupports::STREAM_LOCK)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stream_supports_lock() requires exactly one argument in this compiler build');
        }
        StreamIoRuntime::ensureLinkedForUserScriptLowering($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $handleI32 = $context->builder->trunc(
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_supports_lock() stream'),
                $i64
            ),
            $i32
        );
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            StreamIoRuntime::lookupStreamIoHelper($context, StreamIoRuntime::supportsHelperLogical()),
            [$handleI32, $i32->constInt(VmStreamSupports::STREAM_LOCK, false)]
        );

        return $context->builder->icmp(
            Builder::INT_EQ,
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32),
            $i32->constInt(1, false)
        );
    }
}
