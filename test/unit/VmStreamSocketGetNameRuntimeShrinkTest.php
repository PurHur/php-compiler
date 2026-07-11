<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStreamSocketGetName;
use PHPCompiler\ext\standard\VmStreamSocketGetNamePure;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** stream_socket_get_name() pure /proc path without libc getsockname FFI (#12445). */
final class VmStreamSocketGetNameRuntimeShrinkTest extends TestCase
{
    public function testVmStreamSocketGetNameDelegatesToPureWithoutLibcFfi(): void
    {
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketGetName.php');
        $this->assertStringContainsString('VmStreamSocketGetNamePure::getName', $native);
        $this->assertStringNotContainsString('FFI::cdef', $native);
        $this->assertStringNotContainsString('\\FFI', $native);
        $this->assertStringNotContainsString('getsockname', $native);
        $this->assertStringNotContainsString('getpeername', $native);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketGetNamePure.php');
        $this->assertStringContainsString('/proc/net/tcp', $pure);
        $this->assertStringContainsString('/proc/self/fd/', $pure);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testJitHelperRoutesThroughVmStreamSocketGetName(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamSocketGetNameJitHelper.php');
        $this->assertStringContainsString('VmStreamSocketGetName::getName', $helper);
    }

    public function testVmStreamSocketNativeServerFallsBackToPureWhenFfiDisabled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketNative.php');
        $this->assertStringContainsString('VmStreamSocketPure::server', $source);
    }

    public function testStreamSocketGetNameBindAddressOnLinux(): void
    {
        if (!VmStreamSocketGetNamePure::available()) {
            $this->markTestSkipped('Linux /proc/net only');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$srv = stream_socket_server('tcp://127.0.0.1:0');
$name = stream_socket_get_name($srv, false);
echo is_string($name) && str_starts_with($name, '127.0.0.1:') ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'stream_socket_get_name_probe.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testStreamSocketGetNameWorksWithFfiDisabledOnLinux(): void
    {
        if (!VmStreamSocketGetNamePure::available()) {
            $this->markTestSkipped('Linux /proc/net only');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
$srv = stream_socket_server('tcp://127.0.0.1:0');
$name = stream_socket_get_name($srv, false);
echo is_string($name) && str_starts_with($name, '127.0.0.1:') ? '1' : '0';
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'stream_socket_get_name_ffi_off.php'));
            $this->assertSame('1', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
