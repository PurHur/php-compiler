<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmDebugBacktrace;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Capture suspended-fiber stack traces (issue #6470; php-src ReflectionFiber::getTrace parity).
 */
final class FiberTrace
{
    public static function captureAtSuspend(Frame $handlerFrame, FiberState $fiber): Variable
    {
        $frames = [];
        for ($f = $handlerFrame; null !== $f; $f = $f->parent) {
            $frames[] = $f;
            if ($f->fiberState === $fiber) {
                break;
            }
        }

        return VmDebugBacktrace::buildFromFrames(
            $frames,
            VmDebugBacktrace::IGNORE_ARGS,
            includeHandlers: true,
        );
    }

    public static function requireSuspended(FiberState $fiber, string $method): void
    {
        if (FiberState::STATUS_SUSPENDED !== $fiber->status) {
            if (FiberState::STATUS_INIT === $fiber->status) {
                throw new NativeFiberError('Cannot get fiber trace: The fiber has not been started');
            }
            if (FiberState::STATUS_TERMINATED === $fiber->status) {
                throw new NativeFiberError('Cannot get fiber trace: The fiber has terminated');
            }
            throw new NativeFiberError('Cannot get fiber trace: The fiber is not suspended');
        }
    }
}
