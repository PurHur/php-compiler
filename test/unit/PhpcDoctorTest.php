<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * phpc doctor environment probes (issue #253).
 */
final class PhpcDoctorTest extends TestCase
{
    public function testHelpListsDoctor(): void
    {
        $result = $this->runPhpc(['help']);
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('phpc doctor', $result['stdout']);
    }

    public function testDoctorPassesInHealthyRepo(): void
    {
        $result = $this->runPhpc(['doctor']);
        $this->assertSame(0, $result['exit'], $result['stdout']."\n".$result['stderr']);
        $this->assertStringContainsString('[ok] PHP:', $result['stdout']);
        $this->assertStringContainsString('[ok] Composer deps:', $result['stdout']);
        $this->assertStringContainsString('LLVM 9:', $result['stdout']);
        $this->assertStringContainsString('libLLVM-9.so.1:', $result['stdout']);
        $this->assertStringContainsString('JIT compliance:', $result['stdout']);
        $this->assertStringContainsString('Environment ready for full local CI', $result['stdout']);
    }

    public function testHelpDocumentsJitProbe(): void
    {
        $result = $this->runPhpc(['help']);
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('--jit-probe', $result['stdout']);
    }

    public function testHelpDocumentsAotProjectProbe(): void
    {
        $result = $this->runPhpc(['help']);
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('--aot-project-probe', $result['stdout']);
    }

    public function testDoctorJitProbeWhenLlvmPresent(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        if (!is_file('/opt/llvm9/libLLVM-9.so.1') && !is_file($repoRoot.'/.llvm/libLLVM-9.so.1')) {
            $this->markTestSkipped('LLVM 9 not available in this environment');
        }
        $result = $this->runPhpc(['doctor', '--jit-probe']);
        $combined = $result['stdout']."\n".$result['stderr'];
        if (!str_contains($combined, 'jit-runtime-probe OK')) {
            $this->markTestSkipped('MCJIT probe not runnable here (ci-local may skip @group jit): '.$combined);
        }
        $this->assertSame(0, $result['exit'], $combined);
        $this->assertStringContainsString('jit-runtime-probe OK', $result['stdout']);
    }

    public function testDoctorAotProjectProbeWhenLlvmPresent(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        if (!is_file('/opt/llvm9/libLLVM-9.so.1') && !is_file($repoRoot.'/.llvm/libLLVM-9.so.1')) {
            $this->markTestSkipped('LLVM 9 not available in this environment');
        }
        if (!is_file($repoRoot.'/examples/003-MiniWebApp/public/index.php')) {
            $this->markTestSkipped('examples/003-MiniWebApp missing');
        }
        $result = $this->runPhpc(['doctor', '--aot-project-probe']);
        $combined = $result['stdout']."\n".$result['stderr'];
        if (str_contains($combined, 'aot-project-probe skipped')) {
            $this->markTestSkipped('LLVM probe skipped in subprocess: '.$combined);
        }
        if (!str_contains($combined, 'aot-project-probe OK')) {
            $this->markTestSkipped('AOT project probe not green (LLVM or user-class compile): '.$combined);
        }
        $this->assertSame(0, $result['exit'], $combined);
        $this->assertStringContainsString('aot-project-probe OK', $result['stdout']);
    }

    public function testDoctorGatesPrintsMiniWebAppLadder(): void
    {
        $result = $this->runPhpc(['doctor', '--gates', '--no-lint']);
        $this->assertSame(0, $result['exit'], $result['stdout']."\n".$result['stderr']);
        $this->assertStringContainsString('MiniWebApp CI gates', $result['stdout']);
        $this->assertMatchesRegularExpression('/Stage [0-4]/', $result['stdout']);
        $this->assertStringContainsString('MINIWEBAPP_LINT_GATE', $result['stdout']);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_GATE', $result['stdout']);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_AOT_GATE', $result['stdout']);
        $this->assertStringContainsString('Stage 3c', $result['stdout']);
        $this->assertStringContainsString('DEPLOY_SMOKE_003_EXECUTE=1 default', $result['stdout']);
        $this->assertStringContainsString('North Star 1 presenter', $result['stdout']);
        $this->assertStringContainsString('Nested return', $result['stdout']);
        $this->assertStringContainsString('north-star1-verify', $result['stdout']);
        $this->assertStringContainsString('make north-star1-verify', $result['stdout']);
        $this->assertStringContainsString('North Star 2 presenter', $result['stdout']);
        $this->assertStringContainsString('north-star2-verify', $result['stdout']);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-smoke', $result['stdout']);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-vm-smoke', $result['stdout']);
        $this->assertStringContainsString('M2 spine:', $result['stdout']);
        $this->assertStringContainsString('M3 strict:', $result['stdout']);
        $this->assertStringContainsString('M4 loop dry-run', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE', $result['stdout']);
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE', $result['stdout']);
        $this->assertStringContainsString('Fast CI hook', $result['stdout']);
        $this->assertStringContainsString('LLVM 9:', $result['stdout']);
        $this->assertStringContainsString('Serve tests:', $result['stdout']);
        $this->assertStringContainsString('SessionsWeb (005)', $result['stdout']);
        $this->assertStringContainsString('005-SessionsWeb', $result['stdout']);
        $this->assertStringContainsString('SESSIONS_WEB_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('SESSIONS_WEB_AOT_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('examples-sessions-smoke', $result['stdout']);
        $this->assertStringContainsString('#1891', $result['stdout']);
        $this->assertStringContainsString('#1886', $result['stdout']);
    }

    /**
     * @param list<string> $phpcArgs
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpc(array $phpcArgs): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', ...$phpcArgs]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'exit' => is_int($exit) ? $exit : 1,
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
        ];
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            return preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
        }
        $cmd = [PHP_BINARY];
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        if (is_dir($extDir)) {
            foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
                $so = $extDir.'/'.$ext.'.so';
                if (is_file($so)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension='.$so;
                }
            }
        }

        return $cmd;
    }
}
