<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmPhpFdStream;
use PHPCompiler\ext\standard\VmProcess;
use PHPCompiler\ext\standard\VmProcessProcOpenNative;
use PHPUnit\Framework\TestCase;

/** VmProcess proc_open VM must not call host proc_open when FFI available (#8652). */
final class VmProcessRuntimeShrinkTest extends TestCase
{
    public function testVmProcessRoutesStringCommandThroughNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcess.php');
        $this->assertStringContainsString('VmProcessProcOpenNative::open', $source);
        $this->assertStringContainsString('procOpenHost', $source);
    }

    public function testVmProcessProcOpenNativeUsesLibcNotHostProcOpen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcessProcOpenNative.php');
        $this->assertStringContainsString('fork', $source);
        $this->assertStringContainsString('execvp', $source);
        $this->assertStringContainsString('VmPhpFdStream::adopt', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\proc_open\\(/', $source);
    }

    public function testVmProcessRoutesArrayCommandThroughNativeWhenFfiAvailable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcess.php');
        $this->assertStringContainsString('VmProcessProcOpenNative::openArgv', $source);
    }

    public function testProcOpenArgvEchoWhenFfiAvailable(): void
    {
        if (!VmProcessProcOpenNative::available() || !VmPhpFdStream::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        $desc = [1 => ['pipe', 'w']];
        $result = VmProcess::procOpen(['echo', 'ok'], $desc);
        $this->assertIsArray($result);
        [$procId, $pipes] = $result;
        $out = VmFs::fread($pipes[1], 8192);
        VmFs::fclose($pipes[1]);
        $code = VmProcess::procClose($procId);
        $this->assertSame(0, $code);
        $this->assertSame('ok', trim((string) $out));
    }

    public function testProcOpenEchoWhenFfiAvailable(): void
    {
        if (!VmProcessProcOpenNative::available() || !VmPhpFdStream::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        $desc = [1 => ['pipe', 'w']];
        $result = VmProcess::procOpen('echo ok', $desc);
        $this->assertIsArray($result);
        [$procId, $pipes] = $result;
        $st = VmProcess::procGetStatus($procId);
        $this->assertIsArray($st);
        $this->assertSame('echo ok', $st['command']);
        $out = VmFs::fread($pipes[1], 8192);
        VmFs::fclose($pipes[1]);
        $code = VmProcess::procClose($procId);
        $this->assertSame(0, $code);
    }

    public function testProcOpenThreePipeDescWhenFfiAvailable(): void
    {
        if (!VmProcessProcOpenNative::available() || !VmPhpFdStream::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $result = VmProcess::procOpen('echo ok', $desc);
        $this->assertIsArray($result);
        [$procId, $pipes] = $result;
        $this->assertIsInt($procId);
        $this->assertArrayHasKey(1, $pipes);
        $out = VmFs::fread($pipes[1], 8192);
        $this->assertIsString($out);
        $this->assertSame('ok', trim($out));
        VmFs::fclose($pipes[1]);
        $code = VmProcess::procClose($procId);
        $this->assertSame(0, $code);
    }
}
