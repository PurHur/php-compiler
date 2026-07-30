<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** socket_import_stream JIT routes through SocketImportStreamJitHelper PHP not Error stub (#9217). */
final class SocketImportStreamRuntimeShrinkTest extends TestCase
{
    public function testSocketImportStreamCallUsesJitSocketImportStream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_import_stream.php');
        $this->assertStringContainsString('JitSocketImportStream::invoke', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testSocketImportStreamJitHelperDelegatesToVmSocket(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketImportStreamJitHelper.php');
        $this->assertStringContainsString('VmSocket::canImportStreamHandle', $source);
        $this->assertStringContainsString('VmSocket::registerJitImportedStream', $source);
        $this->assertStringContainsString('TriggerErrorJitHelper::warning', $source);
    }

    public function testSocketImportStreamRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketImportStreamRuntime.php');
        $this->assertStringContainsString('::canImportArgv', $source);
        $this->assertStringContainsString('__compiler_socket_import_can_import', $source);
        $this->assertStringContainsString('__compiler_socket_import_register', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_SELFHOST_AOT', $source);
        $this->assertStringNotContainsString('putenv', $source);
    }

    public function testSpineBundleIncludesSocketImportStreamJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SocketImportStreamJitHelper.php', $spine);
        $this->assertStringContainsString('JitSocketImportStream.php', $spine);
        $this->assertStringContainsString('SocketImportStreamRuntime.php', $spine);
    }
}
