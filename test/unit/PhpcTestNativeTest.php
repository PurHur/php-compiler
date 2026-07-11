<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * phpc test --native subset (issue #15599).
 */
final class PhpcTestNativeTest extends TestCase
{
    public function testNativeSubsetScriptExistsAndIsExecutable(): void
    {
        $script = $this->repoRoot().'/script/bootstrap-native-test-subset.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
    }

    public function testNativeVmComplianceScriptExists(): void
    {
        $script = $this->repoRoot().'/script/bootstrap-native-vm-compliance.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('compliance-subset.manifest', $body);
        $this->assertStringContainsString('bin/vm.php', $body);
        $this->assertStringContainsString('#15599', $body);
    }

    public function testComplianceSubsetManifestExists(): void
    {
        $manifest = $this->repoRoot().'/test/bootstrap-native/compliance-subset.manifest';
        $this->assertFileExists($manifest);
        $body = (string) file_get_contents($manifest);
        $this->assertStringContainsString('magic_script_const/run.php', $body);
        $this->assertStringContainsString('compiler_smoke_standalone.php', $body);
    }

    public function testPhpcTestNativeDispatchesToSubsetScript(): void
    {
        $body = (string) file_get_contents($this->repoRoot().'/bin/phpc.php');
        $this->assertStringContainsString('bootstrap-native-test-subset.sh', $body);
        $this->assertStringContainsString('--native', $body);
        $this->assertStringContainsString('--native cannot be combined with --fast or --bootstrap', $body);
    }

    public function testPhpcTestNativeAndBootstrapAreMutuallyExclusive(): void
    {
        $result = $this->runPhpc(['test', '--native', '--bootstrap']);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('--native cannot be combined', $result['stderr']);
    }

    public function testNativeSubsetPassesInHealthyRepo(): void
    {
        $result = $this->runPhpc(['test', '--native']);
        $combined = $result['stdout']."\n".$result['stderr'];
        $this->assertSame(0, $result['exit'], $combined);
        $this->assertStringContainsString('bootstrap-native-test-subset: ok', $combined);
        $this->assertStringContainsString('bootstrap-native-vm-compliance: OK', $combined);
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param list<string> $phpcArgs
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpc(array $phpcArgs): array
    {
        $repoRoot = $this->repoRoot();
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
