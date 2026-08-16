<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ftp mutate JIT routes through FtpMutateJitHelper PHP (#31427). */
final class FtpMutateRuntimeShrinkTest extends TestCase
{
    /** @dataProvider callSites */
    public function testCallUsesJitFtpMutate(string $file, string $invoke): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/'.$file);
        $this->assertStringContainsString($invoke, $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    /** @return list<array{0: string, 1: string}> */
    public function callSites(): array
    {
        return [
            ['ftp_mkdir.php', 'JitFtpMutate::invokeMkdir'],
            ['ftp_delete.php', 'JitFtpMutate::invokeDelete'],
            ['ftp_rename.php', 'JitFtpMutate::invokeRename'],
            ['ftp_rmdir.php', 'JitFtpMutate::invokeRmdir'],
        ];
    }

    public function testFtpMutateJitHelperUsesLibcThinAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ftp/FtpMutateJitHelper.php');
        $this->assertStringContainsString('SocketsLibcThinAbi::send', $source);
        $this->assertStringContainsString('MKD ', $source);
        $this->assertStringContainsString('DELE ', $source);
        $this->assertStringContainsString('RNFR ', $source);
        $this->assertStringContainsString('RNTO ', $source);
        $this->assertStringContainsString('RMD ', $source);
        $this->assertStringNotContainsString('\\ctype_digit(', $source);
        $this->assertStringNotContainsString('\\substr(', $source);
        $this->assertStringNotContainsString('\\ftp_mkdir(', $source);
    }

    public function testFtpMutateRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtpMutateRuntime.php');
        $this->assertStringContainsString('__compiler_ftp_mkdir', $source);
        $this->assertStringContainsString('__compiler_ftp_delete', $source);
        $this->assertStringContainsString('__compiler_ftp_rename', $source);
        $this->assertStringContainsString('__compiler_ftp_rmdir', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
    }

    public function testSpineBundleIncludesFtpMutateHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FtpMutateJitHelper.php', $spine);
        $this->assertStringContainsString('JitFtpMutate.php', $spine);
        $this->assertStringContainsString('FtpMutateRuntime.php', $spine);
        $this->assertStringContainsString('StringFtpMutate.php', $spine);
    }
}
