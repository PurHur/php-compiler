<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ObOutputJitBridge;
use PHPCompiler\JIT\Builtin\SessionLifecycleRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for session_register_shutdown() (php-src ext/session/session.c; #35330 leftover #4873).
 *
 * Emit {@see __phpc_session_write_close_apply} into {@see Context::$shutdownBlock}
 * (peer {@see JitRegisterShutdown} / {@see JitSessionWriteClose}).
 */
final class JitSessionRegisterShutdown
{
    public static function invoke(Context $context): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        SessionLifecycleRuntime::ensureLinked($context);
        ObOutputJitBridge::ensureShutdownMarkRegistered($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $saved = $context->builder->getInsertBlock();
        $context->builder->positionAtEnd($context->shutdownBlock);
        try {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__phpc_session_write_close_apply'),
                $ptr
            );
        } finally {
            if (null !== $saved) {
                $context->builder->positionAtEnd($saved);
            }
        }

        $context->builder->call($context->lookupFunction('__phpc_shutdown_mark_registered'));

        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

        return $nullPtr;
    }
}
