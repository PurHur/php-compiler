<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT WeakMap count drops after referent unset (#27621 / zend_weakrefs.c).
 *
 * Regression from #26795: clear_object kept LLVM WeakReference slot clear but
 * dropped WeakMap HT purge. Map registry uses LLVM globals + unsetStringKey;
 * unset() also clears weak maps before deferred delref (#4096).
 *
 * @group llvm
 * @group aot
 */
final class WeakMapAotUnsetCount27621Test extends TestCase
{
    public function testAotWeakMapCountAfterReferentUnset(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_weakmap_unset_count_27621.php';
        $bin = sys_get_temp_dir().'/phpc_weakmap_27621_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile.' 2>&1', $out, $code);
        $this->assertSame(0, $code, "compile failed:\n".implode("\n", $out));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runCode);
            $this->assertSame(0, $runCode, "run failed:\n".implode("\n", $runOut));
            $this->assertSame(['42', '0'], $runOut);
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($bin);
        }
    }

    public function testClearObjectEmitsLlvmMapPurge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/WeakRefRegistryRuntime.php');
        $this->assertStringContainsString('phpc_wr_map_count', $source);
        $this->assertStringContainsString('emitClearMapLoop', $source);
        $this->assertStringContainsString('__hashtable__unsetStringKey', $source);
        $this->assertStringContainsString('wr_clear_maps_do', $source);
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('phpc_weakref_clear_object', $jit);
        $this->assertStringContainsString('unset_object_side', $jit);
    }
}
