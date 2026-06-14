<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VM php://stdio wrappers use VmFsStdio libc path, not bare host @fopen on stdio URIs (#4648). */
final class VmFsStdioRuntimeShrinkTest extends TestCase
{
    public function testVmFsFopenRoutesStdioThroughVmFsStdio(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsStdio::isStdioUri', $source);
        $this->assertStringContainsString('VmFsStdio::open', $source);
    }

    public function testVmFsStdioUsesNativeDupOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsStdio.php');
        $this->assertStringContainsString('VmFsStdioNative::openDupFd', $source);
        $this->assertDoesNotMatchRegularExpression('/@fopen\\s*\\(/', $source);
    }

    public function testStreamIoJitDefinesStdioHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php');
        $this->assertStringContainsString('__phpc_try_fopen_stdio', $source);
        $this->assertStringContainsString('php://stdin', $source);
    }
}
