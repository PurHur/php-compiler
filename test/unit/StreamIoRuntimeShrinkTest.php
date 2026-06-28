<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamIoJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** Stream I/O JIT routes standalone + embed through StreamIoJitHelper PHP (#10326, #12956). */
final class StreamIoRuntimeShrinkTest extends TestCase
{
    private const BASELINE_LOC = 1010;

    public function testStreamIoJitDelegatesToRuntimeOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php');
        $this->assertStringContainsString('StreamIoRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitFwrite', $source);
        $this->assertStringNotContainsString('emitFread', $source);
        $this->assertStringNotContainsString('emitFopen', $source);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamIoStandaloneLlvm.php');
    }

    public function testStreamIoJitShrunkAtLeastThirtyPercent(): void
    {
        $loc = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php'), "\n") + 1;
        $this->assertLessThanOrEqual((int) floor(self::BASELINE_LOC * 0.7), $loc, 'StreamIoJit.php LOC');
    }

    public function testStreamIoRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('StreamIoJitHelper::fopenArgv', $source);
        $this->assertStringContainsString('StreamIoJitHelper::freadArgv', $source);
        $this->assertStringContainsString('StreamIoJitHelper::fwriteArgv', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringContainsString('ensureRuntimeAbiDeclared', $source);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $source);
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
