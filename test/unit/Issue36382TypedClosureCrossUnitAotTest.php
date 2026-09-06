<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — typed closure method call across incremental IncludeHelper unit.
 *
 * @group llvm
 * @group aot
 */
final class Issue36382TypedClosureCrossUnitAotTest extends TestCase
{
    public function testTypedClosureAddRouteAcrossIncludeUnit(): void
    {
        $repo = dirname(__DIR__, 2);
        $dir = $repo.'/test/repro/issue_36382_typed_closure_cross_unit';
        $main = $dir.'/main.php';
        $rc = $dir.'/RC.php';
        $this->assertFileExists($main);
        $this->assertFileExists($rc);

        if (!LlvmToolchain::isReady($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $out = tempnam(sys_get_temp_dir(), 'tcross36382_');
        $this->assertNotFalse($out);
        @unlink($out);

        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $env['PHP_COMPILER_AOT_INCREMENTAL_INCLUDES'] = '1';
        $env['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = sys_get_temp_dir()
            .'/phpc-helper-36382-tcross-'.getmypid();
        // Avoid a warm CompileCache hit of a prior sealed-block failure on the same entry.
        $env['PHP_COMPILER_COMPILE_CACHE'] = '0';

        $cmd = sprintf(
            'php -d memory_limit=512M %s --include %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($rc),
            escapeshellarg($out),
            escapeshellarg($main)
        );
        $lines = [];
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
        $joined = trim((string) $stdout."\n".$stderr);
        $this->assertSame(0, $ec, $joined);
        $this->assertFileExists($out);
        $this->assertStringNotContainsString('undefined method', strtolower($joined));

        $runLines = [];
        exec(escapeshellarg($out).' 2>&1', $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $clean = [];
        foreach ($runLines as $line) {
            $t = trim($line);
            if ('' === $t || str_starts_with($t, 'PHP Warning:')) {
                continue;
            }
            $clean[] = $t;
        }
        $this->assertSame(['hello_id'], $clean);
    }
}
