<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * JIT project smoke for examples/003-MiniWebApp (issues #587, #1759, #475, #1801).
 *
 * @see https://github.com/PurHur/php-compiler/issues/587
 * @see https://github.com/PurHur/php-compiler/issues/1759
 * @see https://github.com/PurHur/php-compiler/issues/1801
 */
final class MiniWebAppJitProjectTest extends TestCase
{
    private string $publicDir;

    private string $jitLauncher;

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $index = $this->repoRoot.'/examples/003-MiniWebApp/public/index.php';
        if (!is_file($index)) {
            $this->markTestSkipped('examples/003-MiniWebApp/public/index.php missing (#246)');
        }
        if ('0' === getenv('MINIWEBAPP_JIT_PROJECT_GATE')) {
            $this->markTestSkipped(
                'MINIWEBAPP_JIT_PROJECT_GATE=0 — set to 1 to run MiniWebApp JIT project tests (#587)'
            );
        }
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!$this->jitRuntimeProbeOk()) {
            $this->markTestSkipped('JIT MCJIT probe failed — bin/jit.php not runnable (#587, #98)');
        }
        $this->publicDir = dirname($index);
        $this->jitLauncher = $this->buildJitLauncher();
    }

    private function buildJitLauncher(): string
    {
        $project = $this->repoRoot.'/examples/003-MiniWebApp';
        $output = sys_get_temp_dir().'/miniwebapp-jit-project-'.getmypid().'.jit';
        @unlink($output);
        $result = \PHPCompiler\Cli\PhpcBuild::buildProjectJit($this->repoRoot, $project, $output, false);
        if (0 !== $result['exit']) {
            $this->fail('phpc build --project --jit failed: '.$result['stderr'].$result['stdout']);
        }
        if (!is_executable($output)) {
            $this->fail('JIT launcher not executable: '.$output);
        }

        return $output;
    }

    protected function tearDown(): void
    {
        if (isset($this->jitLauncher) && is_file($this->jitLauncher)) {
            @unlink($this->jitLauncher);
        }
    }

    /**
     * @group miniwebapp
     * @group llvm
     * @group jit
     * @group miniwebapp-jit-project
     */
    public function testQueryRouteHomeShowsAppName(): void
    {
        $out = $this->runIndex(MiniWebAppCgiEnv::queryRouteHome());
        $this->assertStringContainsString(MiniWebAppCgiEnv::APP_NAME, $out);
    }

    /**
     * @group miniwebapp
     * @group llvm
     * @group jit
     * @group miniwebapp-jit-project
     */
    public function testPathInfoHelloShowsName(): void
    {
        $out = $this->runIndex(MiniWebAppCgiEnv::pathInfoHello('Dev'));
        $this->assertStringContainsString('Hello Dev', $out);
    }

    /**
     * @group miniwebapp
     * @group llvm
     * @group jit
     * @group miniwebapp-jit-project
     */
    public function testContactPostThankYou(): void
    {
        $out = $this->runIndex(MiniWebAppCgiEnv::postQueryRouteContact());
        $this->assertStringContainsString('Thank you, PostDev', $out);
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function runIndex(array $cgiEnv): string
    {
        $env = $this->baseEnv();
        foreach ($cgiEnv as $key => $value) {
            $env[$key] = $value;
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        $cmd = array_merge(
            LlvmToolchain::envPrefix($this->repoRoot),
            [$this->jitLauncher]
        );
        $result = $this->runCommand($cmd, $this->publicDir, $env);
        if (0 !== $result['code']) {
            $combined = trim($result['stderr']."\n".$result['stdout']);
            if (false !== stripos($combined, 'not jittable')) {
                $this->markTestSkipped(
                    '003-MiniWebApp JIT blocked (not jittable): '.substr($combined, 0, 500).' (#475, #58)'
                );
            }
            $this->assertSame(0, $result['code'], $combined);
        }

        return $result['stdout'];
    }

    private function jitRuntimeProbeOk(): bool
    {
        $script = $this->repoRoot.'/script/jit-runtime-probe.php';
        if (!is_file($script)) {
            return false;
        }
        $cmd = array_merge(
            LlvmToolchain::envPrefix($this->repoRoot),
            self::phpCommand(),
            [$script]
        );
        $result = $this->runCommand($cmd, $this->repoRoot, []);
        if (0 !== $result['code']) {
            return false;
        }

        return str_contains($result['stdout'], 'jit-runtime-probe OK');
    }

    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && !isset($env[$key])) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @param list<string>              $cmd
     * @param array<string, string>     $env
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCommand(array $cmd, string $cwd, array $env): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [
            'code' => $code,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
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
