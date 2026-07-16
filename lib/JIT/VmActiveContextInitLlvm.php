<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\DomStandaloneAotInitRuntime;

/**
 * Thin standalone user-script AOT: allocate Runtime/vmContext and publish sg_vm_context (#17391).
 */
final class VmActiveContextInitLlvm
{
    private static bool $pending = false;

    private static bool $scheduled = false;

    /** Reset module-static scheduling between deferred AOT init passes (#16075). */
    public static function resetPendingState(): void
    {
        self::$pending = false;
        self::$scheduled = false;
    }

    /** Request __init__ emission once DOM instance-method bridges are linked. */
    public static function requestThinStandaloneInit(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        if (!$context->isThinStandaloneAotMain()) {
            return;
        }
        self::$pending = true;
    }

    /** Emit pending init IR before {@see Context::sealInitFunction()} seals __init__. */
    public static function emitPendingBeforeSeal(Context $context): void
    {
        if (!self::$pending || self::$scheduled) {
            return;
        }
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        if (!$context->isThinStandaloneAotMain()) {
            return;
        }

        DomStandaloneAotInitRuntime::ensureLinked($context);
        self::$scheduled = true;

        $context->emitInInit(static function (Context $ctx): void {
            $object = $ctx->type->object;
            $runtime = RuntimeEmitTuAlloc::emit($ctx);
            $object->markObjectConstructed($runtime);
            RuntimeInitVmContext::emit($ctx, $object, $runtime);

            $ctxSlot = $object->propertyFetch($runtime, 'PHPCompiler\\Runtime', 'vmContext');
            $ctxObj = $ctx->helper->loadValue($ctxSlot);
            $ctx->builder->call($ctx->lookupFunction(DomStandaloneAotInitRuntime::ABI_NAME), $ctxObj);
        });
    }
}
