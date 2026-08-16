<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ftp transfer JIT routes through FtpTransferJitHelper PHP (#31429). */
final class FtpTransferRuntimeShrinkTest extends TestCase
{
    /** @dataProvider callSites */
    public function testCallUsesJitFtpTransfer(string $file, string $invoke): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/'.$file);
        $this->assertStringContainsString($invoke, $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    /** @return list<array{0: string, 1: string}> */
    public function callSites(): array
    {
        return [
            ['ftp_get.php', 'JitFtpTransfer::invokeGet'],
            ['ftp_put.php', 'JitFtpTransfer::invokePut'],
            ['ftp_fget.php', 'JitFtpTransfer::invokeFget'],
            ['ftp_fput.php', 'JitFtpTransfer::invokeFput'],
        ];
    }

    public function testFtpTransferJitHelperUsesLibcThinAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/FtpTransferJitHelper.php');
        $this->assertStringContainsString('SocketsLibcThinAbi::send', $source);
        $this->assertStringContainsString('RETR ', $source);
        $this->assertStringContainsString('STOR ', $source);
        $this->assertStringContainsString('PASV', $source);
        $this->assertStringContainsString('TYPE ', $source);
        $this->assertStringContainsString('jitPasvEnabled', $source);
        $this->assertStringContainsString('@\\file_get_contents(', $source);
        $this->assertStringContainsString('@\\file_put_contents(', $source);
        $this->assertStringContainsString('VmFs::fwrite', $source);
        $this->assertStringContainsString('VmFs::streamGetContents', $source);
        $this->assertStringNotContainsString('\\ctype_digit(', $source);
        $this->assertStringNotContainsString('\\substr(', $source);
        $this->assertStringNotContainsString('\\explode(', $source);
        $this->assertStringNotContainsString('\\ftp_get(', $source);
        $this->assertStringNotContainsString('\\ftp_put(', $source);
    }

    public function testFtpTransferRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtpTransferRuntime.php');
        $this->assertStringContainsString('__compiler_ftp_get', $source);
        $this->assertStringContainsString('__compiler_ftp_put', $source);
        $this->assertStringContainsString('__compiler_ftp_fget', $source);
        $this->assertStringContainsString('__compiler_ftp_fput', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
    }

    public function testSpineBundleIncludesFtpTransferHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FtpTransferJitHelper.php', $spine);
        $this->assertStringContainsString('JitFtpTransfer.php', $spine);
        $this->assertStringContainsString('FtpTransferRuntime.php', $spine);
        $this->assertStringContainsString('StringFtpTransfer.php', $spine);
    }
}
