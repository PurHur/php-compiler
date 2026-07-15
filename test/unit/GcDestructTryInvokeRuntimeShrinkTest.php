<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** GcCollectCyclesRuntime routes destruct try-invoke + release storage through PHP helpers (#18660). */
final class GcDestructTryInvokeRuntimeShrinkTest extends TestCase
{
    private const EMBED_BRIDGE_MAX_LINES = 16;

    public function testGcCollectCyclesRuntimeUsesTryInvokeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcDestructTryInvokeJitHelper', $source);
        $this->assertStringContainsString('implementDestructTryInvokePhpBridge', $source);
        $this->assertStringContainsString('GcDestructTryInvokeJitHelper::tryInvoke', $source);
        $this->assertStringContainsString('if (self::usesPhpRegistry($context)) {
            self::implementDestructTryInvokePhpBridge($context);', $source);
    }

    public function testGcCollectCyclesRuntimeUsesReleaseStorageJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcObjectReleaseStorageJitHelper', $source);
        $this->assertStringContainsString('implementObjectReleaseStoragePhpBridge', $source);
        $this->assertStringContainsString('GcObjectReleaseStorageJitHelper::release', $source);
        $this->assertStringContainsString('if (self::usesPhpRegistry($context)) {
            self::implementObjectReleaseStoragePhpBridge($context);', $source);
    }

    public function testEmbedDestructTryInvokeBridgeIsThinPhpCall(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        if (!preg_match(
            '/private static function implementDestructTryInvokePhpBridge\(Context \$context\): void\s*\{(.*?)\n    \}/s',
            $source,
            $matches
        )) {
            $this->fail('implementDestructTryInvokePhpBridge missing');
        }
        $body = $matches[1];
        $this->assertLessThanOrEqual(
            self::EMBED_BRIDGE_MAX_LINES,
            substr_count($body, "\n"),
            'phpc_destruct_try_invoke must stay a thin PHP bridge (#18660)'
        );
    }

    public function testGcDestructTryInvokeJitHelperUsesRegistryAndNatives(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GcDestructTryInvokeJitHelper.php');
        $this->assertStringContainsString('GcCollectCyclesRegistryJitHelper::destructAlreadyInvokedByObject', $source);
        $this->assertStringContainsString('phpc_object_is_constructed_native', $source);
        $this->assertStringContainsString('markDestructInvokedByObject', $source);
        $this->assertStringContainsString('phpc_object_invoke_destructor_native', $source);
    }

    public function testGcObjectReleaseStorageJitHelperUsesRegistryAndNatives(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GcObjectReleaseStorageJitHelper.php');
        $this->assertStringContainsString('phpc_gc_notify_object_freed_native', $source);
        $this->assertStringContainsString('GcCollectCyclesRegistryJitHelper::removeObject', $source);
        $this->assertStringContainsString('phpc_mm_free_native', $source);
    }

    public function testGcCollectCyclesNativeOpsJitLinksDestructAndReleaseNatives(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesNativeOpsJit.php');
        $this->assertStringContainsString('objectIsConstructed', $source);
        $this->assertStringContainsString('invokeDestructor', $source);
        $this->assertStringContainsString('notifyObjectFreed', $source);
        $this->assertStringContainsString('mmFree', $source);
    }
}
