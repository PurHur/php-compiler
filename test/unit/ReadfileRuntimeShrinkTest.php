<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** readfile JIT routes through ReadfileJitHelper PHP, not libc open/read/write LLVM (#9188). */
final class ReadfileRuntimeShrinkTest extends TestCase
{
    public function testReadfileJitHelperDelegatesToVmFs(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/ReadfileJitHelper.php');
        $this->assertStringContainsString('VmFs::readfile', $source);
        $this->assertStringNotContainsString('lookupFunction(\'open\')', $source);
        $this->assertStringNotContainsString('lookupFunction(\'read\')', $source);
    }

    public function testStringReadfileRoutesThroughReadfileJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringReadfile.php');
        $this->assertStringContainsString('ReadfileJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('open')", $source);
        $this->assertStringNotContainsString("lookupFunction('read')", $source);
        $this->assertStringNotContainsString("lookupFunction('write')", $source);
        $this->assertStringNotContainsString('CHUNK', $source);
    }

    public function testReadfileJitHelperReturnsMinusOneWhenOpenFails(): void
    {
        $this->assertSame(
            -1,
            \PHPCompiler\ext\standard\ReadfileJitHelper::readfile('/no/such/phpc-readfile-jit-helper-'.bin2hex(random_bytes(4)))
        );
    }
}
