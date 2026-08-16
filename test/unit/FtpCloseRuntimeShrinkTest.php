<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ftp_close / ftp_quit JIT routes through FtpCloseJitHelper PHP (#31377). */
final class FtpCloseRuntimeShrinkTest extends TestCase
{
    public function testFtpCloseCallUsesJitFtpClose(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/ftp_close.php');
        $this->assertStringContainsString('JitFtpClose::invoke', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testFtpQuitCallUsesJitFtpClose(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/ftp_quit.php');
        $this->assertStringContainsString('JitFtpClose::invoke', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testFtpCloseJitHelperUsesLibcThinAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/FtpCloseJitHelper.php');
        $this->assertStringContainsString('SocketsLibcThinAbi::close', $source);
        $this->assertStringContainsString('VmFtpCore::jitOwnedFdForLookupKey', $source);
        $this->assertStringContainsString('VmFtpCore::releaseJitOwnedForLookupKey', $source);
        $this->assertStringNotContainsString('\\ftp_close(', $source);
    }

    public function testFtpCloseRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtpCloseRuntime.php');
        $this->assertStringContainsString('::closeForHandle', $source);
        $this->assertStringContainsString('__compiler_ftp_close', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesFtpCloseHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FtpCloseJitHelper.php', $spine);
        $this->assertStringContainsString('JitFtpClose.php', $spine);
        $this->assertStringContainsString('FtpCloseRuntime.php', $spine);
        $this->assertStringContainsString('StringFtpClose.php', $spine);
    }
}
