<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ftp list JIT routes through FtpListJitHelper PHP (#31428). */
final class FtpListRuntimeShrinkTest extends TestCase
{
    /** @dataProvider callSites */
    public function testCallUsesJitFtpList(string $file, string $invoke): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/'.$file);
        $this->assertStringContainsString($invoke, $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    /** @return list<array{0: string, 1: string}> */
    public function callSites(): array
    {
        return [
            ['ftp_rawlist.php', 'JitFtpList::invokeRawlist'],
            ['ftp_mlsd.php', 'JitFtpList::invokeMlsd'],
        ];
    }

    public function testFtpListJitHelperUsesLibcThinAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/FtpListJitHelper.php');
        $this->assertStringContainsString('SocketsLibcThinAbi::send', $source);
        $this->assertStringContainsString('LIST ', $source);
        $this->assertStringContainsString('MLSD ', $source);
        $this->assertStringContainsString('PASV', $source);
        $this->assertStringContainsString('jitPasvEnabled', $source);
        $this->assertStringNotContainsString('\\ctype_digit(', $source);
        $this->assertStringNotContainsString('\\substr(', $source);
        $this->assertStringNotContainsString('\\explode(', $source);
        $this->assertStringNotContainsString('\\ftp_rawlist(', $source);
    }

    public function testFtpListRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtpListRuntime.php');
        $this->assertStringContainsString('__compiler_ftp_rawlist', $source);
        $this->assertStringContainsString('__compiler_ftp_mlsd', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
    }

    public function testSpineBundleIncludesFtpListHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FtpListJitHelper.php', $spine);
        $this->assertStringContainsString('JitFtpList.php', $spine);
        $this->assertStringContainsString('FtpListRuntime.php', $spine);
        $this->assertStringContainsString('StringFtpList.php', $spine);
    }
}
