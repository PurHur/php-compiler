<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\Web\Superglobals;

/**
 * Active executing frame for NestedJIT/AOT helpers (#22547).
 *
 * Kept as a static host-side lookup so NestedJIT of thin helpers does not lower
 * `$vm->currentExecutingFrame()` on an untyped receiver (falls back to the helper
 * class name and throws "Call to undefined method …::currentexecutingframe()").
 */
final class VmExecutingFrame
{
    public static function requireFromActiveContext(): Frame
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'VmExecutingFrame::requireFromActiveContext() requires an active VM context in this compiler build'
            );
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException(
                'VmExecutingFrame::requireFromActiveContext() requires an active VM in this compiler build'
            );
        }
        $frame = $vm->currentExecutingFrame();
        if (null === $frame) {
            throw new \LogicException(
                'VmExecutingFrame::requireFromActiveContext() requires an active executing frame in this compiler build'
            );
        }

        return $frame;
    }
}
