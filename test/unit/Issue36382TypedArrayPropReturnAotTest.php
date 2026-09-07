<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — untyped FastRoute row export across units under AOT.
 *
 * @group aot
 */
final class Issue36382TypedArrayPropReturnAotTest extends TestCase
{
    public function testUntypedFastRouteRowsExportRuns(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_typed_array_prop_return.php';
        $this->assertFileExists($src);
        if (!\PHPCompiler\LlvmToolchain::isReady($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $out = tempnam(sys_get_temp_dir(), 'arrprop36382_');
        $this->assertNotFalse($out);
        @unlink($out);
        $env = $_ENV;
        \PHPCompiler\LlvmToolchain::applyProcessEnv($env, $repo);
        $env['PHP_COMPILER_CACHE'] = '0';
        $env['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = sys_get_temp_dir()
            .'/phpc-helper-36382-arrprop-'.getmypid();
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
        $this->assertSame(
            ['B', 'A', 'M=GET P=/hello ID=route0', 'OK'],
            array_map('trim', $runLines)
        );
    }
}
