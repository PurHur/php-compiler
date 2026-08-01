<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GcCollectCyclesJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * GcCollectCyclesCollectRuntime routes stats through GcCollectCyclesJitHelper PHP (#9183, #13882, #26532).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (peer #26472).
 */
final class GcCollectCyclesCollectRuntimeShrinkTest extends TestCase
{
    private const EMBED_IMPL_MAX_LINES = 20;

    public function testGcCollectCyclesRuntimeUsesJitHelperBridge(): void
    {
        $runtimeSource = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcCollectCyclesCollectRuntime', $runtimeSource);
        $this->assertStringContainsString('implementCollectCyclesPhpBridge', $runtimeSource);
        $this->assertStringNotContainsString('private static function implementCollectCycles(', $runtimeSource);
        $this->assertStringNotContainsString('gc_collect_entry', $runtimeSource);

        $bridgeSource = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesCollectRuntime.php');
        $this->assertStringContainsString('GcCollectCyclesJitHelper', $bridgeSource);
        $this->assertStringContainsString('recordNativeCollect', $bridgeSource);
        $this->assertStringContainsString('collectCyclesEmbed', $bridgeSource);
    }

    public function testGcCollectCyclesCollectRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesCollectRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPLLVM\\BasicBlock;', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
        $this->assertStringNotContainsString('captureInsertBlock', $source);
        $this->assertStringNotContainsString('restoreInsertBlock', $source);
        $this->assertLessThan(180, \substr_count($source, "\n") + 1);
    }

    public function testEmbedCollectCyclesImplIsThinPhpBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        if (!preg_match(
            '/private static function implementCollectCyclesPhpBridge\(Context \$context\): void\s*\{(.*?)\n    \}/s',
            $source,
            $matches
        )) {
            $this->fail('implementCollectCyclesPhpBridge missing');
        }
        $body = $matches[1];
        $this->assertLessThanOrEqual(
            self::EMBED_IMPL_MAX_LINES,
            substr_count($body, "\n"),
            'embed phpc_gc_collect_cycles_impl must stay a thin PHP bridge (#13882)'
        );
        $this->assertStringNotContainsString('collect_impl_init', $body);
        $this->assertStringNotContainsString('collect_sweep', $body);
        $this->assertStringNotContainsString('phpc_gc_visit_object', $source);
    }

    public function testJitGcCollectCyclesUsesRuntimeNotNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGcCollectCycles.php');
        $this->assertStringContainsString('GcCollectCyclesRuntime', $source);
        $this->assertStringNotContainsString('GcCollectCyclesNative', $source);
    }

    public function testGcCollectCyclesJitHelperRecordNativeCollect(): void
    {
        GcCollectCyclesJitHelper::resetForTest();
        $this->assertSame(2, GcCollectCyclesJitHelper::recordNativeCollect(2));
        $this->assertSame(1, GcCollectCyclesJitHelper::runs());
        $this->assertSame(2, GcCollectCyclesJitHelper::totalCollected());
        $this->assertFalse(GcCollectCyclesJitHelper::isRunning());
        $this->assertFalse(GcCollectCyclesJitHelper::isProtected());
    }

    public function testGcCollectCyclesJitHelperDocumentsEmbedPhpScan(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GcCollectCyclesJitHelper.php');
        $bridgeSource = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesCollectRuntime.php');
        $this->assertStringContainsString('GcCollectCyclesJitHelper', $bridgeSource);
        $this->assertStringContainsString('collectCyclesEmbed', $source);
        $this->assertStringContainsString('GcCollectCyclesNativeScanJitHelper', $source);
        $this->assertStringContainsString('CycleCollector', $source);
    }
}
