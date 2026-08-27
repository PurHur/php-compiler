<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: session_register_shutdown() via shutdown-block emit (#35330 leftover of #4873).
 *
 * @see php-src ext/session/session.c PHP_FUNCTION(session_register_shutdown)
 *
 * @group llvm
 * @group aot
 */
final class SessionRegisterShutdown35330AotTest extends TestCase
{
    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/issue_35330_session_register_shutdown_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/standard/JitSessionRegisterShutdown.php');
        $this->assertStringContainsString('__phpc_session_write_close_apply', $jit);
        $this->assertStringContainsString('shutdownBlock', $jit);
        $this->assertStringContainsString('__phpc_shutdown_mark_registered', $jit);
        $src = (string) file_get_contents($root.'/ext/session/session_register_shutdown.php');
        $this->assertStringContainsString('JitSessionRegisterShutdown::invoke', $src);
        $this->assertStringNotContainsString(
            'session_register_shutdown() is not lowered for JIT/AOT in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/session_register_shutdown.c');
    }

    private function runPhp(string $src): string
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $bin = sys_get_temp_dir().'/phpc_srs_35330_'.getmypid();
        @unlink($bin);
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, implode("\n", $cout));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
