<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5332 / #21564: session lifecycle without phpc_session_lifecycle.c or
 * SessionStart C-symbol forwarder. NestedJIT ensureLinked is covered elsewhere
 * when HashTable helper compile is healthy; this file stays source-level.
 *
 * @group aot-lint
 */
final class SessionLifecycleRuntimeStandaloneTest extends TestCase
{
    public function testKernelImplementsStartApplyDirectlyNoForwarder(): void
    {
        $kernel = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitSessionLifecycleKernel.php');
        $this->assertStringContainsString("lookupFunction('__phpc_session_start_apply')", $kernel);
        $this->assertStringNotContainsString('phpc_session_start_runtime', $kernel);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $kernel);
        $this->assertStringContainsString('implementStandaloneRuntime', $kernel);
        $this->assertStringContainsString('implementStandaloneWriteClose', $kernel);

        $start = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/SessionStart.php');
        $this->assertStringNotContainsString('implementStandaloneForwarder', $start);
        $this->assertStringNotContainsString('registerRuntimeDeclaration', $start);
        $this->assertStringNotContainsString('RUNTIME_C_SYMBOL', $start);
    }

    public function testPhpcSessionLifecycleCRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_session_lifecycle.c');
        $linker = file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_session_lifecycle.c', $linker);
    }
}
