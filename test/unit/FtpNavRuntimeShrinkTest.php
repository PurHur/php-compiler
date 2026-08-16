<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ftp navigation JIT routes through FtpNavJitHelper PHP (#31379). */
final class FtpNavRuntimeShrinkTest extends TestCase
{
    /** @dataProvider callSites */
    public function testCallUsesJitFtpNav(string $file, string $invoke): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/'.$file);
        $this->assertStringContainsString($invoke, $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    /** @return list<array{0: string, 1: string}> */
    public function callSites(): array
    {
        return [
            ['ftp_pasv.php', 'JitFtpNav::invokePasv'],
            ['ftp_chdir.php', 'JitFtpNav::invokeChdir'],
            ['ftp_cdup.php', 'JitFtpNav::invokeCdup'],
            ['ftp_pwd.php', 'JitFtpNav::invokePwd'],
        ];
    }

    public function testFtpNavJitHelperUsesLibcThinAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/FtpNavJitHelper.php');
        $this->assertStringContainsString('SocketsLibcThinAbi::send', $source);
        $this->assertStringContainsString('PASV', $source);
        $this->assertStringContainsString('CWD ', $source);
        $this->assertStringContainsString('CDUP', $source);
        $this->assertStringContainsString('PWD', $source);
        $this->assertStringNotContainsString('\\ctype_digit(', $source);
        $this->assertStringNotContainsString('\\ftp_pasv(', $source);
    }

    public function testFtpNavRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtpNavRuntime.php');
        $this->assertStringContainsString('__compiler_ftp_pasv', $source);
        $this->assertStringContainsString('__compiler_ftp_chdir', $source);
        $this->assertStringContainsString('__compiler_ftp_cdup', $source);
        $this->assertStringContainsString('__compiler_ftp_pwd', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
    }

    public function testSpineBundleIncludesFtpNavHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FtpNavJitHelper.php', $spine);
        $this->assertStringContainsString('JitFtpNav.php', $spine);
        $this->assertStringContainsString('FtpNavRuntime.php', $spine);
        $this->assertStringContainsString('StringFtpNav.php', $spine);
    }
}
