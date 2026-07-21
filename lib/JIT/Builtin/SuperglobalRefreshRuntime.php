<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitSuperglobalRefreshKernel;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for __superglobals__refresh (#9907, #21888).
 *
 * MCJIT embed: {@see \PHPCompiler\JIT\SuperglobalInit::implementRefresh} copies VM tables.
 * Standalone AOT (user-script + self-host): {@see JitSuperglobalRefreshKernel} native LLVM.
 *
 * Former NestedJIT {@see \PHPCompiler\Web\SuperglobalRefreshJitHelper} bridge removed (#21888):
 * `: HashTable` returns TypeError under self-host stubs (`HashTable, int returned` — peer #20652 /
 * #13571). Zend/VM SSOT remains {@see \PHPCompiler\Web\Superglobals} + the JitHelper unit tests.
 * php-src: main/php_variables.c
 */
final class SuperglobalRefreshRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    /** User-script standalone: native LLVM refresh without nested JIT during init (#13571, #13717, #15417). */
    public static function ensureUserScriptRefresh(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        JitSuperglobalRefreshKernel::implement($context);
    }

    public static function ensureUserScriptRefreshPrerequisites(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        JitSuperglobalRefreshKernel::ensurePrerequisites($context);
    }

    /** After preg prelink on a temporary full-init Context — no nested Multipart JIT (#16075). */
    public static function ensureUserScriptRefreshPrerequisitesAfterPregPrelink(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        JitSuperglobalRefreshKernel::ensureDeferredEmitPrerequisites($context);
    }

    public static function ensureUserScriptRefreshEmit(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        JitSuperglobalRefreshKernel::emitRefresh($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__superglobals__refresh');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_EMBED === $context->loadType) {
            return;
        }

        // Always kernel for standalone — NestedJIT HashTable helper aborts on self-host (#21888).
        JitSuperglobalRefreshKernel::implement($context);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__superglobals__refresh');
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException('__superglobals__refresh missing after SuperglobalRefreshRuntime (#9907)');
        }
        $context->registerFunction('__superglobals__refresh', $fn);
    }
}
