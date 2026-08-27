<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: get_object_vars($this) from subclass with parent private must compile and match Zend (#35479).
 *
 * inheritParentInstanceProperties copies parent-private slots onto the child for allocation;
 * instancePropertyVisibilityMeta must still report prop_info->ce so child scope omits them
 * (zend_check_property_access / leftover of #27020).
 *
 * @group llvm
 * @group aot
 */
final class GetObjectVarsParentPrivateChildScope35479AotTest extends TestCase
{
    public function testChildScopeOmitsParentPrivateMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_get_object_vars_scope.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $zend = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zendRc);
        $this->assertSame(0, $zendRc, 'Zend reference failed');
        $zendOut = trim(implode("\n", $zend));

        $bin = sys_get_temp_dir().'/phpc_35479_'.getmypid().'.bin';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $root);
        $cmd = [PHP_BINARY, $root.'/bin/compile.php', '-o', $bin, $src];
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
        $this->assertSame($zendOut, $aotOut, 'AOT get_object_vars child scope must match Zend');
    }
}
