<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\VM\VmActiveContextJitHelper;

/** Active VM context for DOM JIT/AOT helpers (#17130, #17391). */
final class VmDomJitFrame
{
    public static function vmContext(): VmContext
    {
        return VmActiveContextJitHelper::resolve();
    }

    public static function executingFrame(): ?Frame
    {
        $ctx = self::vmContext();
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            return null;
        }

        return $vm->currentExecutingFrame();
    }
}
