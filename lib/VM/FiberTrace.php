<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmDebugBacktrace;
use PHPCompiler\ext\standard\VmStreamArg;
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

    public static function requireFiberObject(Variable $arg, string $function, int $param): ObjectEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($fiber) must be of type Fiber, %s given',
                $function,
                $param,
                VmStreamArg::debugTypeName($arg)
            ));
        }
        $object = $arg->toObject();
        if (null === $object->fiberState) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($fiber) must be of type Fiber, %s given',
                $function,
                $param,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function fiberStateFromReflection(ObjectEntry $reflection): FiberState
    {
        $target = $reflection->getProperty(ReflectionSupport::PROP_FIBER_TARGET)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $target->type) {
            throw new \LogicException('ReflectionFiber missing wrapped fiber');
        }

        return FiberSupport::fiberFromObject($target->toObject());
    }

    public static function requireIntrospectableFiber(FiberState $fiber): void
    {
        if (FiberState::STATUS_INIT === $fiber->status || FiberState::STATUS_TERMINATED === $fiber->status) {
            throw new \Error('Cannot fetch information from a fiber that has not been started or is terminated');
        }
    }

    public static function executingLine(FiberState $fiber): int
    {
        self::requireIntrospectableFiber($fiber);
        $frame = $fiber->frame;
        if (null === $frame) {
            return 0;
        }

        $line = FatalSite::lineFromOpcodes($frame);
        if ($line > 0) {
            return $line;
        }

        return self::firstSourceLineInBlock($frame);
    }

    public static function executingFile(FiberState $fiber): string|false
    {
        self::requireIntrospectableFiber($fiber);
        $frame = $fiber->frame;
        if (null === $frame) {
            return false;
        }
        if (null !== $frame->block) {
            $path = $frame->block->scriptPath();
            if ('' !== $path) {
                return $path;
            }
        }
        if ('' !== $frame->scriptPath) {
            return $frame->scriptPath;
        }

        return false;
    }

    private static function firstSourceLineInBlock(Frame $frame): int
    {
        if (null === $frame->block) {
            return 0;
        }
        foreach ($frame->block->opCodes as $op) {
            if (null !== $op->sourceLocation && $op->sourceLocation->startLine > 0) {
                return $op->sourceLocation->startLine;
            }
        }

        return 0;
    }
}
