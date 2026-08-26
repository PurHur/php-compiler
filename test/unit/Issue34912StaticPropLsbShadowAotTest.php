<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: static::$prop late-static binding when a child shadows the property (#34912).
 *
 * @group llvm
 * @group aot
 */
final class Issue34912StaticPropLsbShadowAotTest extends TestCase
{
    public function testAotStaticPropLsbUsesCalledClassWhenChildShadows(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34912_static_prop_lsb_shadow.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc);
        $this->assertSame(['static:B self:A B::B A::A'], $expected);

        $bin = sys_get_temp_dir().'/phpc_34912_'.getmypid().'.bin';
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
        $this->assertSame(0, $compileRc, 'compile failed: '.substr((string) $stderr, 0, 500));

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $out));
        $this->assertSame($expected, $out);
    }

    public function testLsbStaticPropFetchWired(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/ObjectStaticPropertyLlvm.php');
        $this->assertStringContainsString('staticPropertyFetchByRuntimeClassId', $jit);
        $this->assertStringContainsString('#34912', $jit);
        $this->assertStringContainsString('fetchByRuntimeClassId', $llvm);
        $this->assertStringContainsString('#34912', $llvm);
    }
}
