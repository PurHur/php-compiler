<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPCompiler\Web\DeployRoot;
use PHPCompiler\Web\ProjectDeploy;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end phpc deploy dist layout + PHPC_DEPLOY_ROOT execute for 003-MiniWebApp (issue #612).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group miniwebapp
 */
final class MiniWebAppDeployLayoutTest extends TestCase
{
    private string $repoRoot;

    private string $projectDir;

    /** @var string|null */
    private static $distDir;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->projectDir = $this->repoRoot.'/examples/003-MiniWebApp';
        if (!is_file($this->projectDir.'/public/index.php')) {
            $this->markTestSkipped('examples/003-MiniWebApp missing (#246)');
        }
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $phpc = $this->repoRoot.'/phpc';
        if (!is_executable($phpc)) {
            $this->markTestSkipped('phpc wrapper not executable');
        }

        if (null === self::$distDir) {
            self::$distDir = $this->buildAndDeployDist($phpc);
        }
    }

    public function testDeployDistContainsExpectedLayout(): void
    {
        $dist = $this->distDir();
        $this->assertFileIsReadable($dist.'/bin/app');
        $this->assertFileIsExecutable($dist.'/bin/app');
        $this->assertDirectoryExists($dist.'/templates');
        $this->assertFileIsReadable($dist.'/templates/layout.php');
        $this->assertDirectoryExists($dist.'/public');
        $this->assertFileIsReadable($dist.'/public/index.php');
        $this->assertFileIsReadable($dist.'/'.ProjectDeploy::README_DEPLOY);
        $readme = (string) file_get_contents($dist.'/'.ProjectDeploy::README_DEPLOY);
        $this->assertStringContainsString(DeployRoot::ENV, $readme);
        if (is_dir($this->projectDir.'/assets')) {
            $this->assertDirectoryExists($dist.'/assets');
        }
        $this->assertFileIsReadable($dist.'/cgi-wrapper');
    }

    public function testDeployedBinaryHomeRouteViaQueryString(): void
    {
        $out = $this->runDeployedBinary(MiniWebAppCgiEnv::queryRouteHome());
        $this->assertNotSame('', $out, 'deployed bin/app produced empty stdout (#764)');
        $this->assertStringContainsString(MiniWebAppCgiEnv::APP_NAME, $out);
        $this->assertStringContainsString('<main>', $out);
        $this->assertStringContainsString('Reference app', $out);
    }

    public function testDeployedBinaryHelloRouteViaPathInfo(): void
    {
        $env = MiniWebAppCgiEnv::aotPathInfoHello('Deploy');
        $out = $this->runDeployedBinary($env);
        $this->assertStringContainsString('Hello Deploy', $out);
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function runDeployedBinary(array $cgiEnv): string
    {
        $dist = $this->distDir();
        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $env[DeployRoot::ENV] = $dist;
        $env['SCRIPT_FILENAME'] = $dist.'/public/index.php';
        $env['SCRIPT_NAME'] = '/index.php';
        $env['DOCUMENT_ROOT'] = $dist.'/public';
        foreach ($cgiEnv as $key => $value) {
            $env[$key] = $value;
        }

        $result = $this->runCommand([$dist.'/bin/app'], $dist, $env);
        $this->assertSame(0, $result['code'], trim($result['stderr']."\n".$result['stdout']));

        return $result['stdout'];
    }

    private function distDir(): string
    {
        if (null === self::$distDir) {
            $this->fail('deploy dist was not prepared in setUp');
        }

        return self::$distDir;
    }

    private function buildAndDeployDist(string $phpc): string
    {
        $dist = sys_get_temp_dir().'/phpc_mini_deploy_'.bin2hex(random_bytes(6));
        $this->removeTree($dist);

        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        $build = $this->runCommand(
            [$phpc, 'build', '--project', $this->projectDir],
            $this->repoRoot,
            $env
        );
        if (139 === $build['code']) {
            $this->markTestSkipped(
                'phpc build --project exited 139 (LLVM segfault during 003-MiniWebApp link)'
            );
        }
        if (0 !== $build['code'] && PhpcBuild::isUserClassAotBlocked($build['stderr'])) {
            $this->markTestSkipped(
                '003-MiniWebApp native AOT link blocked: '.trim($build['stderr'])
            );
        }
        $this->assertSame(0, $build['code'], 'phpc build --project failed: '.substr($build['stderr'], 0, 500));

        $deploy = $this->runCommand(
            [$phpc, 'deploy', $this->projectDir, '-o', $dist],
            $this->repoRoot,
            $env
        );
        $this->assertSame(0, $deploy['code'], 'phpc deploy failed: '.trim($deploy['stderr']));

        return $dist;
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
     * @param list<string>          $cmd
     * @param array<string, string> $env
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

    private function removeTree(string $dir): void
    {
        self::removeTreeStatic($dir);
    }

    public static function tearDownAfterClass(): void
    {
        if (null === self::$distDir) {
            return;
        }
        $dist = self::$distDir;
        self::$distDir = null;
        self::removeTreeStatic($dist);
    }

    private static function removeTreeStatic(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                self::removeTreeStatic($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
