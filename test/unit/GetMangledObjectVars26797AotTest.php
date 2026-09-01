<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: get_mangled_object_vars / get_object_vars must box native int props (#26797).
 *
 * @see php-src ext/standard/var.c PHP_FUNCTION(get_mangled_object_vars)
 *
 * @group llvm
 * @group aot
 */
final class GetMangledObjectVars26797AotTest extends TestCase
{
    public function testAotGetMangledObjectVarsIntPropsMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_aot_get_mangled_object_vars.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $zend = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zendRc);
        $this->assertSame(0, $zendRc, 'Zend reference failed');
        $zendOut = trim(implode("\n", $zend));

        $bin = sys_get_temp_dir().'/phpc_26797_'.getmypid().'.bin';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $root);
        $cmd = [
            PHP_BINARY,
            $root.'/bin/compile.php',
            '-o',
            $bin,
            $src,
        ];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileRc = proc_close($proc);
        $this->assertSame(0, $compileRc, 'compile failed: '.substr((string) $stderr, 0, 800));
        $this->assertFileExists($bin);

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        @unlink($bin);
        $aotOut = trim(implode("\n", $out));
        $this->assertSame(0, $runRc, 'AOT run failed: '.$aotOut);
        $this->assertSame($zendOut, $aotOut);
    }
}
