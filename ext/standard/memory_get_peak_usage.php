<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\MemoryAccounting;
use PHPLLVM\Value;

/** memory_get_peak_usage() — peak memory usage bytes (issue #3134). */
final class memory_get_peak_usage extends Internal
{
    public function __construct()
    {
        parent::__construct('memory_get_peak_usage');
    }

    public function execute(Frame $frame): void
    {
        $realUsage = self::resolveRealUsage($frame);
        if (null === $frame->returnVar) {
            VmMemory::getPeakUsage($realUsage);

            return;
        }
        if ($realUsage) {
            $frame->returnVar->int(VmMemory::getPeakUsage(true));

            return;
        }
        MemoryAccounting::syncPeakFromCurrent();
        $frame->returnVar->int(
            MemoryAccounting::markPeakQuery(MemoryAccounting::peakBytes())
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                'memory_get_peak_usage() expects at most 1 argument, '.\count($args).' given'
            );
        }
        // Compile-time null under strict: catchable TypeError then stop IR (#30346 peer).
        if (isset($args[0]) && $context->callerStrictTypes && (
            JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)
        )) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'memory_get_peak_usage(): Argument #1 ($real_usage) must be of type bool, null given'
            );
            JitNativeString::ensureInsertBlock($context);
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitMemory::getPeakUsage($context, $args[0] ?? null);
    }

    private static function resolveRealUsage(Frame $frame): bool
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'memory_get_peak_usage() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (0 === $argc) {
            return false;
        }
        // Z_PARAM_BOOL — caller strict_types → TypeError on null (#30346 peer).
        return VmMemory::resolveUsageArg($frame, 0, 'memory_get_peak_usage');
    }
}
