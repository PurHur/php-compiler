<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_language() via MbLanguageJitHelper (#35259 leftover of #4636 / peer #35221).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_language)
 *
 * @group llvm
 * @group aot
 */
final class MbLanguageRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_language_runtime.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbLanguageJitHelper.php');
        $this->assertStringContainsString('function canonicalizeArgv', $helper);
        $this->assertStringContainsString('CODE_UNI', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbLanguageRuntime.php');
        $this->assertStringContainsString('canonicalizeHelper', $runtime);
        $this->assertStringContainsString('G_LANGUAGE_CODE', $runtime);
        $this->assertStringContainsString('MbLanguageJitHelper::canonicalizeArgv', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbLanguage.php');
        $this->assertStringContainsString('MbLanguageRuntime', $jit);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_language.php');
        $this->assertStringContainsString('JitMbLanguage::invoke', $src);
        $this->assertStringNotContainsString(
            'mb_language() JIT setter is not supported',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_language.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/phpc_mb_language.c');
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
        $bin = sys_get_temp_dir().'/mb_language_'.getmypid().'_'.md5($src);
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
        } finally {
            chdir($cwd);
            if (is_file($bin)) {
                @unlink($bin);
            }
        }

        return implode("\n", $out);
    }
}
