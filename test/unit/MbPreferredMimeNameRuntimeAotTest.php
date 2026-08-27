<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_preferred_mime_name() via MbPreferredMimeNameJitHelper (#34298 leftover of #13100).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_preferred_mime_name)
 *
 * @group llvm
 * @group aot
 */
final class MbPreferredMimeNameRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_preferred_mime_name_runtime_aot.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbPreferredMimeNameJitHelper.php');
        $this->assertStringContainsString('function assertEncodingArgv', $helper);
        $this->assertStringContainsString('function preferredMimeArgv', $helper);
        $this->assertStringNotContainsString('MbstringEncodingRegistry::', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbPreferredMimeNameRuntime.php');
        $this->assertStringContainsString('assertEncodingHelper', $runtime);
        $this->assertStringContainsString('preferredHelper', $runtime);
        $this->assertStringContainsString('MbPreferredMimeNameJitHelper::preferredMimeArgv', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbPreferredMimeName.php');
        $this->assertStringContainsString('ensureLinked($context)', $jit);
        $this->assertLessThan(
            strpos($jit, 'JitStringBuiltinArg::lower'),
            strpos($jit, 'MbPreferredMimeNameRuntime::ensureLinked')
        );
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_preferred_mime_name.php');
        $this->assertStringContainsString('JitMbPreferredMimeName::invoke', $src);
        $this->assertStringNotContainsString(
            'mb_preferred_mime_name() JIT is not supported in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_preferred_mime_name.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/phpc_mb_preferred_mime_name.c');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_preferred_mime_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
