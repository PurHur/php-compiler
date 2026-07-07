<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\Web\Superglobals;

/** Active VM context for DOM JIT/AOT helpers (#17130). */
final class VmDomJitFrame
{
    public static function vmContext(): VmContext
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'VmDomJitFrame requires an active VM context in this compiler build'
            );
        }

        return $ctx;
    }

    public static function executingFrame(): ?Frame
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            return null;
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            return null;
        }

        return $vm->currentExecutingFrame();
    }
}
