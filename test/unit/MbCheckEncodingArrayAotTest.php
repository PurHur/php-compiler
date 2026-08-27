<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_check_encoding(array) via compileTimeArray string fold / TYPE_VALUE walk (#35365).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_check_encoding)
 *
 * @group llvm
 * @group aot
 */
final class MbCheckEncodingArrayAotTest extends TestCase
{
    public function testAotArrayOperandMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_check_encoding_array.php';
        $vm = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame($vm, $aot);
        $this->assertStringContainsString('ok=true', $vm);
        $this->assertStringContainsString('bad=false', $vm);
        $this->assertStringContainsString('litbad=false', $vm);
        $this->assertStringContainsString('empty=true', $vm);
    }

    public function testCompileTimeArrayHandlesStringElems(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbCheckEncoding.php');
        $this->assertStringContainsString('compileTimeStringList', $jit);
        $this->assertStringContainsString('is_string($elem)', $jit);
        $this->assertStringContainsString('lowerRuntimeValueBox', $jit);
        $this->assertStringContainsString('TYPE_HASHTABLE', $jit);
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_check_arr_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
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
