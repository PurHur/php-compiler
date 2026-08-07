<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ftp_connect JIT routes through FtpConnectJitHelper PHP (#27393). */
final class FtpConnectRuntimeShrinkTest extends TestCase
{
    public function testFtpConnectCallUsesJitFtpConnect(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/ftp_connect.php');
        $this->assertStringContainsString('JitFtpConnect::invoke', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testFtpConnectJitHelperUsesLibcThinAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/FtpConnectJitHelper.php');
        $this->assertStringContainsString('SocketsLibcThinAbi::socket', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::connectInet', $source);
        $this->assertStringContainsString('VmFtpCore::registerJitOwnedFd', $source);
        $this->assertStringContainsString('TriggerErrorJitHelper::warning', $source);
        $this->assertStringNotContainsString('\\ftp_connect(', $source);
    }

    public function testFtpConnectRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtpConnectRuntime.php');
        $this->assertStringContainsString('::connectFdArgv', $source);
        $this->assertStringContainsString('__compiler_ftp_connect_fd', $source);
        $this->assertStringContainsString('__compiler_ftp_connect_register', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesFtpConnectHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FtpConnectJitHelper.php', $spine);
        $this->assertStringContainsString('JitFtpConnect.php', $spine);
        $this->assertStringContainsString('FtpConnectRuntime.php', $spine);
        $this->assertStringContainsString('StringFtpConnect.php', $spine);
    }
}
