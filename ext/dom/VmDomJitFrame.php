<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\Web\Superglobals;

/** Active frame lookup for DOM JIT helpers — not nested-compiled (#17130). */
final class VmDomJitFrame
{
    public static function vmContext(): VmContext
    {
        $ctx = self::executingFrame()->vmContext;
        if (null === $ctx) {
            throw new \LogicException('VmDomJitFrame requires VM context');
        }

        return $ctx;
    }

    public static function executingFrame(): Frame
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'VmDomJitFrame requires an active VM context in this compiler build'
            );
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException(
                'VmDomJitFrame requires an active VM in this compiler build'
            );
        }
        $frame = $vm->currentExecutingFrame();
        if (null === $frame) {
            throw new \LogicException(
                'VmDomJitFrame requires an active executing frame in this compiler build'
            );
        }

        return $frame;
    }
}
