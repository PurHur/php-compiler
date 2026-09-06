<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — AOT invoke of a Closure via a callable parameter (`$cb()`).
 *
 * @group aot
 */
final class Issue36382CallableParamInvokeAotTest extends TestCase
{
    public function testCallableParamInvokeRunsClosure(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_callable_param_invoke.php';
        $this->assertFileExists($src);
        if (!\PHPCompiler\LlvmToolchain::isReady($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $out = tempnam(sys_get_temp_dir(), 'cbparam36382_');
        $this->assertNotFalse($out);
        @unlink($out);
        $env = $_ENV;
        \PHPCompiler\LlvmToolchain::applyProcessEnv($env, $repo);
        // CompileCache::isEnabled() reads PHP_COMPILER_CACHE (not COMPILE_CACHE).
        $env['PHP_COMPILER_CACHE'] = '0';
        $env['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = sys_get_temp_dir()
            .'/phpc-helper-36382-cbparam-'.getmypid();
        $cmd = sprintf(
            'php -d memory_limit=512M %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $ec = proc_close($proc);
        $this->assertSame(0, $ec, trim((string) $stdout."\n".$stderr));
        $this->assertFileExists($out);
        $runLines = [];
        exec(escapeshellarg($out).' 2>&1', $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $this->assertSame(['CB_OK', 'OK'], array_map('trim', $runLines));
    }
}
