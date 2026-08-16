<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ftp query JIT routes through FtpQueryJitHelper PHP (#31380). */
final class FtpQueryRuntimeShrinkTest extends TestCase
{
    /** @dataProvider callSites */
    public function testCallUsesJitFtpQuery(string $file, string $invoke): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/'.$file);
        $this->assertStringContainsString($invoke, $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    /** @return list<array{0: string, 1: string}> */
    public function callSites(): array
    {
        return [
            ['ftp_size.php', 'JitFtpQuery::invokeSize'],
            ['ftp_mdtm.php', 'JitFtpQuery::invokeMdtm'],
            ['ftp_systype.php', 'JitFtpQuery::invokeSystype'],
            ['ftp_nlist.php', 'JitFtpQuery::invokeNlist'],
        ];
    }

    public function testFtpQueryJitHelperUsesLibcThinAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/FtpQueryJitHelper.php');
        $this->assertStringContainsString('SocketsLibcThinAbi::send', $source);
        $this->assertStringContainsString('SIZE ', $source);
        $this->assertStringContainsString('MDTM ', $source);
        $this->assertStringContainsString('SYST', $source);
        $this->assertStringContainsString('NLST ', $source);
        $this->assertStringContainsString('PASV', $source);
        $this->assertStringContainsString('jitPasvEnabled', $source);
        $this->assertStringNotContainsString('\\ctype_digit(', $source);
        $this->assertStringNotContainsString('\\substr(', $source);
        $this->assertStringNotContainsString('\\ftp_size(', $source);
        $this->assertStringNotContainsString('\\ftp_nlist(', $source);
    }

    public function testFtpQueryRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtpQueryRuntime.php');
        $this->assertStringContainsString('__compiler_ftp_size', $source);
        $this->assertStringContainsString('__compiler_ftp_mdtm', $source);
        $this->assertStringContainsString('__compiler_ftp_systype', $source);
        $this->assertStringContainsString('__compiler_ftp_nlist', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
    }

    public function testSpineBundleIncludesFtpQueryHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FtpQueryJitHelper.php', $spine);
        $this->assertStringContainsString('JitFtpQuery.php', $spine);
        $this->assertStringContainsString('FtpQueryRuntime.php', $spine);
        $this->assertStringContainsString('StringFtpQuery.php', $spine);
    }
}
