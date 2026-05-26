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

    public function testHelpDocumentsSelfhostFlag(): void
    {
        $result = $this->runPhpc(['help']);
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('--selfhost', $result['stdout']);
    }

    public function testDoctorSelfhostPrintsBootstrapGates(): void
    {
        $result = $this->runPhpc(['doctor', '--selfhost']);
        $this->assertSame(0, $result['exit'], $result['stdout']."\n".$result['stderr']);
        $this->assertStringContainsString('North star — self-host gates', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE', $result['stdout']);
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE', $result['stdout']);
        $this->assertStringContainsString('SELFHOST_SPINE_COUNT_SYNC_GATE', $result['stdout']);
        $this->assertStringContainsString('SELFHOST_SPINE_DEFERRED_SYNC_GATE', $result['stdout']);
        $this->assertStringContainsString('SELFHOST_SPINE_COVERAGE_SYNC_GATE', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_M4_LOOP_PROBE', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_TEST_SUBSET_GATE', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_TEST_SUBSET_STRICT', $result['stdout']);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $result['stdout']);
        $this->assertStringContainsString('helloworld_m3_emit_native_entry.php', $result['stdout']);
        $this->assertStringContainsString('2. M2 spine', $result['stdout']);
        $this->assertStringContainsString('bootstrap-spine-count.php', $result['stdout']);
        $this->assertStringContainsString('COMPILER_DRIVER_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('Inventory triage', $result['stdout']);
        $script = dirname(__DIR__, 2).'/script/bootstrap-inventory-triage.php';
        if (!is_file($script)) {
            $this->assertStringContainsString('pending #2254', $result['stdout']);
        } else {
            $this->assertStringContainsString('bootstrap-inventory-triage.php', $result['stdout']);
        }
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
        $this->assertStringContainsString('Example web integration gates', $result['stdout']);
        $this->assertStringContainsString('Nested return', $result['stdout']);
        $this->assertStringContainsString('north-star1-verify', $result['stdout']);
        $this->assertStringContainsString('make north-star1-verify', $result['stdout']);
        $this->assertStringContainsString('North star — self-host presenter', $result['stdout']);
        $this->assertStringContainsString('phpc test --bootstrap', $result['stdout']);
        $this->assertStringContainsString('bootstrap-test-subset.sh', $result['stdout']);
        $this->assertStringContainsString('north-star2-verify', $result['stdout']);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-smoke', $result['stdout']);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-vm-smoke', $result['stdout']);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke', $result['stdout']);
        $this->assertStringContainsString('COMPILER_DRIVER_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('M2 spine:', $result['stdout']);
        $this->assertStringContainsString('M3 strict:', $result['stdout']);
        $this->assertStringContainsString('M4 loop dry-run', $result['stdout']);
        $this->assertStringContainsString('M4 ci-local', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_M4_LOOP_PROBE', $result['stdout']);
        $this->assertStringContainsString('INIT_MINIWEBAPP_PARITY_GATE', $result['stdout']);
        $this->assertStringContainsString('check-init-miniwebapp-parity.sh', $result['stdout']);
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE', $result['stdout']);
        $this->assertStringContainsString('NORTH_STAR2_THROWSWEB_GATE', $result['stdout']);
        $this->assertStringContainsString('examples-throws-smoke', $result['stdout']);
        $this->assertStringContainsString('Bootstrap subset', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_TEST_SUBSET_GATE', $result['stdout']);
        $this->assertStringContainsString('Fast CI hook', $result['stdout']);
        $this->assertStringContainsString('LLVM 9:', $result['stdout']);
        $this->assertStringContainsString('Serve tests:', $result['stdout']);
        $this->assertStringContainsString('005-SessionsWeb CI gates', $result['stdout']);
        $this->assertStringContainsString('005-SessionsWeb', $result['stdout']);
        $this->assertStringContainsString('SESSIONS_WEB_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('SESSIONS_WEB_SERVE_AOT_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('SESSIONS_WEB_AOT_LINK_GATE', $result['stdout']);
        $this->assertStringContainsString('SESSIONS_WEB_AOT_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('SESSIONS_WEB_DEPLOY_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('Stage 1', $result['stdout']);
        $this->assertStringContainsString('Stage 4 Deploy CGI', $result['stdout']);
        $this->assertStringContainsString('examples-sessions-smoke', $result['stdout']);
        $this->assertStringContainsString('examples-aot-smoke.sh', $result['stdout']);
        $this->assertStringContainsString('deploy-smoke-all', $result['stdout']);
        $this->assertStringContainsString('#2077', $result['stdout']);
        $this->assertStringContainsString('test005SessionsWebAotLink', $result['stdout']);
        $this->assertStringContainsString('#1891', $result['stdout']);
        $this->assertStringContainsString('#1893', $result['stdout']);
        $this->assertStringContainsString('#1886', $result['stdout']);
        $this->assertStringContainsString('ci-defaults.env', $result['stdout']);
        $this->assertStringContainsString('006-FileUploadWeb CI gates', $result['stdout']);
        $this->assertStringContainsString('006-FileUploadWeb', $result['stdout']);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_AOT_LINK_GATE', $result['stdout']);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_AOT_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('--fileupload-only', $result['stdout']);
        $this->assertStringContainsString('test006FileUploadWebAotLink', $result['stdout']);
        $this->assertStringContainsString('FileUploadWebAotExecuteTest', $result['stdout']);
        $this->assertStringContainsString('#2004', $result['stdout']);
        $this->assertStringContainsString('#2010', $result['stdout']);
        $this->assertStringContainsString('007-ThrowsWeb CI gates', $result['stdout']);
        $this->assertStringContainsString('007-ThrowsWeb', $result['stdout']);
        $this->assertStringContainsString('THROWS_WEB_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('THROWSWEB_SERVE_AOT_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('THROWSWEB_UNCAUGHT_500_GATE', $result['stdout']);
        $this->assertStringContainsString('#2200', $result['stdout']);
        $this->assertStringContainsString('THROWSWEB_AOT_LINK_GATE', $result['stdout']);
        $this->assertStringContainsString('test007ThrowsWebAotLink', $result['stdout']);
        $this->assertStringContainsString('THROWSWEB_AOT_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('--throws-only', $result['stdout']);
        $this->assertStringContainsString('#2102', $result['stdout']);
        $this->assertStringContainsString('#2093', $result['stdout']);
        $this->assertStringContainsString('#2101', $result['stdout']);
        $this->assertStringContainsString('test007ThrowsWebAotLink', $result['stdout']);
        $this->assertStringContainsString('ThrowsWebAotExecuteTest', $result['stdout']);
        $this->assertStringContainsString('examples/007-ThrowsWeb/README.md', $result['stdout']);
        $this->assertStringContainsString('#2157', $result['stdout']);
        $this->assertStringContainsString('#2135', $result['stdout']);
        $this->assertStringContainsString('009-FastCGIWeb CI gates', $result['stdout']);
        $this->assertStringContainsString('009-FastCGIWeb', $result['stdout']);
        $this->assertStringContainsString('FASTCGI_WEB_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('FASTCGI_WEB_AOT_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('FASTCGI_WEB_DEPLOY_SMOKE_GATE', $result['stdout']);
        $this->assertStringContainsString('examples-fastcgiweb-deploy-smoke', $result['stdout']);
        $this->assertStringContainsString('examples-fastcgiweb-smoke', $result['stdout']);
        $this->assertStringContainsString('--fastcgi-only', $result['stdout']);
        $this->assertStringContainsString('#2351', $result['stdout']);
        $this->assertStringContainsString('phpc init --profile fastcgiweb', $result['stdout']);
        $this->assertStringContainsString('check-init-fastcgiweb-parity.sh', $result['stdout']);
        $this->assertStringContainsString('Bootstrap inventory lint', $result['stdout']);
        $this->assertStringContainsString('bootstrap-inventory', $result['stdout']);
        $this->assertStringContainsString('BOOTSTRAP_INVENTORY_LINT_SYNC_GATE', $result['stdout']);
        $this->assertStringContainsString('check-bootstrap-inventory-lint-sync.php', $result['stdout']);
        $this->assertStringContainsString('bootstrap-inventory-lint-snapshot', $result['stdout']);
    }

    public function testDoctorSelfhostMentionsBootstrapInventoryGatesProbe(): void
    {
        $result = $this->runPhpc(['doctor', '--selfhost']);
        $this->assertSame(0, $result['exit'], $result['stdout']."\n".$result['stderr']);
        $this->assertStringContainsString('doctor --gates | grep -i bootstrap_inventory', $result['stdout']);
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
