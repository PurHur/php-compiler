<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitEnvLocalKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for __compiler_env_local_* via EnvLocalJitHelper PHP (#9814, #13431, #19809).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitEnvLocalKernel}.
 * Type always-on shells dropped (#32729); Context ensureMinimal always-on dropped (#34807) —
 * getenv()/putenv() do not look up this ABI (StringGetenv / PutenvJitHelper). Call
 * {@see ensureLinked} only when an explicit env-local bridge is required; bootstrap-aot
 * uses {@see ensureBootstrapAotStubLinked}.
 */
final class EnvLocalRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitEnvLocalKernel::ensureLinked($context);
    }

    /** bootstrap-aot-link: linkable putenv/getenv ABI without nested EnvLocalJitHelper JIT (#1492). */
    public static function ensureBootstrapAotStubLinked(Context $context): void
    {
        JitEnvLocalKernel::ensureBootstrapAotStubLinked($context);
    }

    public static function implement(Context $context): void
    {
        JitEnvLocalKernel::implement($context);
    }
}
