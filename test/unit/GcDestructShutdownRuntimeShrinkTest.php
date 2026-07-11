<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** GcCollectCyclesRuntime embed shutdown walk routes through GcDestructShutdownJitHelper PHP (#15852). */
final class GcDestructShutdownRuntimeShrinkTest extends TestCase
{
    private const EMBED_SHUTDOWN_IMPL_MAX_LINES = 16;

    public function testGcCollectCyclesRuntimeUsesShutdownJitHelperOnEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcDestructShutdownJitHelper', $source);
        $this->assertStringContainsString('implementRunShutdownDestructorsPhpBridge', $source);
        $this->assertStringContainsString('shutdown_php_entry', $source);
        $this->assertStringContainsString('GcDestructShutdownJitHelper::runShutdownDestructors', $source);
    }

    public function testEmbedShutdownBridgeIsThinPhpCall(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        if (!preg_match(
            '/private static function implementRunShutdownDestructorsPhpBridge\(Context \$context\): void\s*\{(.*?)\n    \}/s',
            $source,
            $matches
        )) {
            $this->fail('implementRunShutdownDestructorsPhpBridge missing');
        }
        $body = $matches[1];
        $this->assertLessThanOrEqual(
            self::EMBED_SHUTDOWN_IMPL_MAX_LINES,
            substr_count($body, "\n"),
            'embed phpc_gc_run_shutdown_destructors must stay a thin PHP bridge (#15852)'
        );
        $this->assertStringNotContainsString('shutdown_loop_head', $body);
        $this->assertStringNotContainsString('shutdown_drain_head', $body);
    }

    public function testGcDestructShutdownJitHelperUsesNativeBridges(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GcDestructShutdownJitHelper.php');
        $this->assertStringContainsString('phpc_destruct_try_invoke_native', $source);
        $this->assertStringContainsString('phpc_object_release_storage_native', $source);
        $this->assertStringContainsString('GcCollectCyclesRegistryJitHelper', $source);
        $this->assertStringContainsString('GcDestructAllowDelrefJitHelper::setAllowDelref', $source);
    }

    public function testGcCollectCyclesNativeOpsJitLinksShutdownSymbols(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesNativeOpsJit.php');
        $this->assertStringContainsString('destructTryInvoke', $source);
        $this->assertStringContainsString('releaseObjectStorage', $source);
        $this->assertStringContainsString('phpc_destruct_try_invoke', $source);
        $this->assertStringContainsString('phpc_object_release_storage', $source);
    }
}
