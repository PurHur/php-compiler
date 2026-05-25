<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * AOT CLI execute gate for examples/003-MiniWebApp (issues #747, #783).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group miniwebapp
 * @group miniwebapp-aot-execute
 */
final class MiniWebAppAotExecuteTest extends TestCase
{
    private string $repoRoot;

    private string $binary;

    protected function setUp(): void
    {
        $gate = getenv('MINIWEBAPP_AOT_EXECUTE_GATE');
        if (false !== $gate && '' !== $gate && '1' !== $gate) {
            $this->markTestSkipped(
                'MINIWEBAPP_AOT_EXECUTE_GATE=0 — set to 1 (default) to run MiniWebApp AOT execute tests'
            );
        }
        $this->repoRoot = dirname(__DIR__, 2);
        $project = $this->repoRoot.'/examples/003-MiniWebApp';
        if (!is_file($project.'/public/index.php')) {
            $this->markTestSkipped('examples/003-MiniWebApp missing (#246)');
        }
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $phpc = $this->repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }

        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $project],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderr = false !== $stderr ? $stderr : '';
        if (0 !== $exit && PhpcBuild::isUserClassAotBlocked($stderr)) {
            $this->markTestSkipped(
                '003-MiniWebApp native AOT execute blocked: '.trim($stderr)
            );
        }
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));

        $this->binary = $project.'/.phpc/bin/app';
        $this->assertFileExists($this->binary);
    }

    public function testQueryRouteHomeShowsAppName(): void
    {
        $out = $this->runBinaryWithCgiEnv(MiniWebAppCgiEnv::queryRouteHome());
        $this->assertNotSame('', $out, 'expected HTML stdout from AOT binary');
        $this->assertStringContainsString(MiniWebAppCgiEnv::APP_NAME, $out);
    }

    public function testQueryRouteHelloWithName(): void
    {
        $out = $this->runBinaryWithCgiEnv(MiniWebAppCgiEnv::queryRouteHello());
        $this->assertStringContainsString('Hello Dev', $out);
    }

    public function testPathInfoHelloWithName(): void
    {
        $out = $this->runBinaryWithCgiEnv(MiniWebAppCgiEnv::aotPathInfoHello());
        $this->assertStringContainsString('Hello Dev', $out);
    }

    public function testPostQueryRouteContactThankYou(): void
    {
        $out = $this->runBinaryWithCgiEnv(MiniWebAppCgiEnv::postQueryRouteContact());
        $this->assertStringContainsString('Thank you, PostDev', $out);
    }

    public function testQueryRouteApiStatus(): void
    {
        // Minimal fixture parity: test/aot/ClassMethodJsonApiAotTest.php (#849, #1820).
        $out = $this->runBinaryWithCgiEnv(MiniWebAppCgiEnv::queryRouteApiStatus());
        $this->assertStringContainsString('"ok":true', $out);
        $this->assertStringContainsString('"service":"003-MiniWebApp"', $out);
    }

    public function testPathInfoApiStatus(): void
    {
        $out = $this->runBinaryWithCgiEnv(MiniWebAppCgiEnv::aotPathInfoApiStatus());
        $this->assertStringContainsString('"ok":true', $out);
        $this->assertStringContainsString('"service":"003-MiniWebApp"', $out);
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function runBinaryWithCgiEnv(array $cgiEnv): string
    {
        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        foreach (MiniWebAppCgiEnv::aotFrontController($this->repoRoot) as $key => $value) {
            $env[$key] = $value;
        }
        foreach ($cgiEnv as $key => $value) {
            $env[$key] = $value;
        }

        // Repo root cwd so phpunit.xml LD_LIBRARY_PATH=./.llvm resolves (#747).
        return $this->runCommand([$this->binary], $this->repoRoot, $env)['stdout'];
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
        $this->assertSame(
            0,
            $code,
            trim(($stderr !== false ? $stderr : '')."\n".($stdout !== false ? $stdout : ''))
        );

        return [
            'code' => $code,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
        ];
    }
}
