<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group bootstrap */
final class BootstrapHonestCompileLibTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }
    public function testHonestCompileLibDetectsSidecarRecovery(): void
    {
        $lib = self::$root.'/script/bootstrap-honest-compile-lib.sh';
        $this->assertFileExists($lib);
        $log = <<<'LOG'
helloworld_compile_smoke: parseAndCompile returned null (parser/CFG spine)
bootstrap-compile-invoke: gen-0 sidecar emit fallback /build/.m3_compiler_lib_aot_blob -> /build/out (#3046)
bootstrap-compile-invoke: native parse spine null — recovered via gen-0 sidecar (#1492)
LOG;
        $code = $this->runBashCheck($log);
        $this->assertSame(0, $code, 'expected sidecar recovery detection');
    }

    public function testHonestCompileLibPassesCleanNativeLog(): void
    {
        $log = <<<'LOG'
bootstrap-compile-invoke: /build/bin-compile-aot-inventory -o /build/out /compiler/test/foo.php (gen-0 compiled)
helloworld_compile_smoke: compile OK -> /build/out
LOG;
        $code = $this->runBashCheck($log);
        $this->assertSame(1, $code, 'expected no sidecar recovery');
    }

    public function testHonestCompileGateFailsWhenEnabled(): void
    {
        $log = 'recovered via gen-0 sidecar';
        $lib = self::$root.'/script/bootstrap-honest-compile-lib.sh';
        $cmd = 'source '.escapeshellarg($lib)
            .'; BOOTSTRAP_HONEST_COMPILE_GATE=1 bootstrap_honest_compile_gate_check '
            .escapeshellarg($log).' test-label';
        $proc = proc_open(['bash', '-lc', $cmd], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, self::$root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('BOOTSTRAP_HONEST_COMPILE_GATE=1', (string) $stderr);
    }

    public function testBootstrapLoopProbeDocumentsHonestCompileFlag(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-loop-probe.sh');
        $this->assertStringContainsString('--honest-compile', $script);
        $this->assertStringContainsString('BOOTSTRAP_HONEST_COMPILE_GATE=1', $script);
    }

    public function testBootstrapInitScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-init.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('BOOTSTRAP_M5_NO_ZEND=1', $body);
        $this->assertStringContainsString('--with-composer', $body);
    }

    public function testPhpcBootstrapInitWiring(): void
    {
        $phpc = (string) file_get_contents(self::$root.'/bin/phpc.php');
        $this->assertStringContainsString("case 'bootstrap':", $phpc);
        $this->assertStringContainsString('bootstrap-init.sh', $phpc);
    }

    private function runBashCheck(string $log): int
    {
        $lib = self::$root.'/script/bootstrap-honest-compile-lib.sh';
        $cmd = 'source '.escapeshellarg($lib)
            .'; bootstrap_honest_compile_log_uses_sidecar_recovery '
            .escapeshellarg($log);
        $proc = proc_open(['bash', '-lc', $cmd], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, self::$root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc);
    }
}
