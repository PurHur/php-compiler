<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ftp_login JIT routes through FtpLoginJitHelper PHP (#31378). */
final class FtpLoginRuntimeShrinkTest extends TestCase
{
    public function testFtpLoginCallUsesJitFtpLogin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/ftp_login.php');
        $this->assertStringContainsString('JitFtpLogin::invoke', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testFtpLoginJitHelperUsesLibcThinAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/FtpLoginJitHelper.php');
        $this->assertStringContainsString('SocketsLibcThinAbi::send', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::recv', $source);
        $this->assertStringContainsString('VmFtpCore::jitOwnedFdForLookupKey', $source);
        $this->assertStringContainsString('USER ', $source);
        $this->assertStringContainsString('PASS ', $source);
        $this->assertStringNotContainsString('\\ftp_login(', $source);
    }

    public function testFtpLoginRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtpLoginRuntime.php');
        $this->assertStringContainsString('::loginArgv', $source);
        $this->assertStringContainsString('__compiler_ftp_login', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesFtpLoginHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FtpLoginJitHelper.php', $spine);
        $this->assertStringContainsString('JitFtpLogin.php', $spine);
        $this->assertStringContainsString('FtpLoginRuntime.php', $spine);
        $this->assertStringContainsString('StringFtpLogin.php', $spine);
    }
}
