<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * AOT CLI execute gate for examples/003-MiniWebApp (issues #747, #764, #783).
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
        if ('1' !== getenv('MINIWEBAPP_AOT_EXECUTE_GATE')) {
            $this->markTestSkipped(
                'MINIWEBAPP_AOT_EXECUTE_GATE=0 (default) — enable when #764/#747 execute is green'
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
                '003-MiniWebApp native AOT execute blocked (#764): '.trim($stderr)
            );
        }
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));

        $this->binary = $project.'/.phpc/bin/app';
        $this->assertFileExists($this->binary);
    }

    public function testQueryRouteHomeShowsAppName(): void
    {
        $out = $this->runBinaryWithCgiEnv([
            'REQUEST_METHOD' => 'GET',
            'QUERY_STRING' => 'route=home',
        ]);
        $this->assertNotSame('', $out, 'expected HTML stdout from AOT binary');
        $this->assertStringContainsString('MiniWebApp', $out);
    }

    public function testQueryRouteHelloWithName(): void
    {
        $out = $this->runBinaryWithCgiEnv([
            'REQUEST_METHOD' => 'GET',
            'QUERY_STRING' => 'route=hello&name=Dev',
        ]);
        $this->assertStringContainsString('Hello Dev', $out);
    }

    public function testPathInfoHelloWithName(): void
    {
        $out = $this->runBinaryWithCgiEnv([
            'REQUEST_METHOD' => 'GET',
            'PATH_INFO' => '/hello',
            'QUERY_STRING' => 'name=Dev',
        ]);
        $this->assertStringContainsString('Hello Dev', $out);
    }

    public function testPostQueryRouteContactThankYou(): void
    {
        $out = $this->runBinaryWithCgiEnv([
            'REQUEST_METHOD' => 'POST',
            'QUERY_STRING' => 'route=contact',
            'REQUEST_BODY' => 'name=PostDev',
        ]);
        $this->assertStringContainsString('Thank you, PostDev', $out);
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function runBinaryWithCgiEnv(array $cgiEnv): string
    {
        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $publicDir = $this->repoRoot.'/examples/003-MiniWebApp/public';
        $env['SCRIPT_FILENAME'] = $publicDir.'/index.php';
        $env['SCRIPT_NAME'] = '/index.php';
        $env['DOCUMENT_ROOT'] = $publicDir;
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
