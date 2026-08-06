<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for uksort()/uasort() (#27217).
 *
 * @group llvm
 * @group aot
 */
final class UsortKeyedAot27217Test extends TestCase
{
    public function testAotUksortUasortClosuresMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_uksort_uasort_27217.php';
        $bin = sys_get_temp_dir().'/phpc_uksort_27217_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $want = "a,b,c\n1,2,3\n";
        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                // timeout via proc — hang is a failure for this path (#27217).
                $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                $proc = proc_open(escapeshellarg($bin), $descriptors, $pipes);
                $this->assertIsResource($proc);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $buf = '';
                $deadline = microtime(true) + 3.0;
                while (microtime(true) < $deadline) {
                    $buf .= stream_get_contents($pipes[1]);
                    $status = proc_get_status($proc);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(20000);
                }
                $status = proc_get_status($proc);
                if ($status['running']) {
                    proc_terminate($proc, 9);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($proc);
                    $this->fail('run '.($i + 1).': timed out after 3s; got '.$buf);
                }
                $buf .= stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $runRc = proc_close($proc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.$buf);
                $this->assertSame($want, $buf, 'run '.($i + 1));
            }
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($bin);
        }
    }

    public function testUsortRuntimeRoutesKeyedClosuresViaLlvm(): void
    {
        $runtime = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/UsortRuntime.php'
        );
        $this->assertStringContainsString('UsortKeyedLlvm::sortKeysWithClosure', $runtime);
        $this->assertStringContainsString('UsortKeyedLlvm::sortValuesWithClosure', $runtime);
        $llvm = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/UsortKeyedLlvm.php'
        );
        $this->assertStringContainsString('JitStringCompare::strcmp', $llvm);
        $this->assertStringContainsString('reorderKeyedPairs', $llvm);
        $this->assertStringNotContainsString('NestedClosureInvoke', $llvm);
    }
}
