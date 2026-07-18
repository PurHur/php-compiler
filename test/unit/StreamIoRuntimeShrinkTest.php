<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamIoJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/**
 * Stream I/O JIT: embed via StreamIoJitHelper PHP; thin AOT via isThinStandaloneAotMain + JitStreamIoKernel (#10326, #12956, #19462, #19530, #20229, #20576).
 */
final class StreamIoRuntimeShrinkTest extends TestCase
{
    private const BASELINE_LOC = 1010;

    public function testStreamIoJitDelegatesEmbedToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php');
        $this->assertStringContainsString('StreamIoRuntime::ensureLinked', $source);
        $this->assertStringContainsString('JitStreamIoKernel', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('isStandaloneInitPhase', $source);
        $this->assertStringContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('emitFwrite', $source);
        $this->assertStringNotContainsString('emitFread', $source);
        $this->assertStringNotContainsString('emitFopen', $source);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $source);
    }

    public function testStreamIoJitShrunkAtLeastThirtyPercent(): void
    {
        $loc = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php'), "\n") + 1;
        $this->assertLessThanOrEqual((int) floor(self::BASELINE_LOC * 0.7), $loc, 'StreamIoJit.php LOC');
    }

    public function testStreamIoRuntimeUsesJitHelperForEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('StreamIoJitHelper::fopenArgv', $source);
        $this->assertStringContainsString('StreamIoJitHelper::freadArgv', $source);
        $this->assertStringContainsString('StreamIoJitHelper::fwriteArgv', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('ensureRuntimeAbiDeclared', $source);
        $this->assertStringContainsString('isStandaloneInitPhase', $source);
        // Thin AOT upgrades via libc kernel — nested VmFs helpers skip __init__ (#16075, #19462, #19530, #20229).
        $this->assertStringContainsString('JitStreamIoKernel', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('UserScriptAotEnv', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $source);
        // M3 defer bag must not fold standaloneInitPhase (#20576).
        $deferPos = strpos($source, 'function shouldDeferHeavyStreamIoEmitters');
        $this->assertNotFalse($deferPos);
        $deferChunk = substr($source, $deferPos, 700);
        $this->assertStringNotContainsString('standaloneInitPhase', $deferChunk);
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER', $deferChunk);
    }

    public function testUserScriptStreamIoKernelExistsForAotHandles(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamIoStandaloneLlvm.php');
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString('implementForUserScriptLowering', $source);
        $this->assertStringContainsString('emitStreamSupports', $source);
        // Dead inventory dispatch deleted (#20576) — only user-script lowering remains.
        $this->assertStringNotContainsString('function implement(', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('implementDeferredStreamIoStubs', $source);
    }

    public function testStreamIoJitHelperMemoryRoundTrip(): void
    {
        $handle = StreamIoJitHelper::fopenArgv('php://memory', 'w+b');
        $this->assertGreaterThanOrEqual(0, $handle);

        $written = StreamIoJitHelper::fwriteArgv($handle, 'hello', 5);
        $this->assertSame(5, $written);

        VmFs::fseek($handle, 0, \SEEK_SET);
        $data = StreamIoJitHelper::freadArgv($handle, 5);
        $this->assertSame('hello', $data);

        VmFs::fclose($handle);
    }
}
