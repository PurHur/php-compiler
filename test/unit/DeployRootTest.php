<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\DeployRoot;
use PHPCompiler\Web\SourceBundler;

/**
 * PHPC_DEPLOY_ROOT for deployed AOT binaries (issue #585).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DeployRootTest extends TestCase
{
    private static ?bool $llvmReady = null;

    public function testResolvePathUsesEnvWhenSet(): void
    {
        $previous = getenv(DeployRoot::ENV);
        putenv(DeployRoot::ENV.'=/opt/deploy');
        try {
            $this->assertSame(
                '/opt/deploy/templates/page.html',
                DeployRoot::resolvePath('templates/page.html', '/build/templates/page.html')
            );
        } finally {
            if (false === $previous) {
                putenv(DeployRoot::ENV);
            } else {
                putenv(DeployRoot::ENV.'='.$previous);
            }
        }
    }

    public function testSourceBundlerRewritesDirForProjectRoot(): void
    {
        $dir = sys_get_temp_dir().'/phpc_deploy_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/src', 0777, true));
        try {
            file_put_contents($dir.'/phpc.json', '{}');
            file_put_contents($dir.'/entry.php', "<?php\nrequire __DIR__ . '/src/view.php';\n");
            file_put_contents($dir.'/src/view.php', "<?php\n\$x = __DIR__;\n");
            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/entry.php',
                [realpath($dir.'/src/view.php') ?: $dir.'/src/view.php'],
                $dir
            );
            $srcDir = realpath($dir.'/src') ?: $dir.'/src';
            $this->assertStringContainsString(var_export($srcDir, true), $bundled);
            $this->assertStringNotContainsString('__DIR__', $bundled);
            $this->assertStringNotContainsString('phpc_deploy_path(', $bundled);
        } finally {
            @unlink($dir.'/phpc.json');
            @unlink($dir.'/entry.php');
            @unlink($dir.'/src/view.php');
            @rmdir($dir.'/src');
            @rmdir($dir);
        }
    }

    public function testAotBinaryReadsTemplateViaDeployRoot(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }

        $repoRoot = dirname(__DIR__, 2);
        $project = sys_get_temp_dir().'/phpc_deploy_proj_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($project));
        $this->assertTrue(mkdir($project.'/.phpc/bin', 0777, true));
        $this->assertTrue(mkdir($project.'/templates', 0777, true));

        $fallback = $project.'/templates/needle.html';
        file_put_contents($fallback, 'compile-tree');
        file_put_contents(
            $project.'/entry.php',
            "<?php\necho phpc_deploy_path('templates/needle.html', '".$fallback."');\n"
        );
        file_put_contents(
            $project.'/phpc.json',
            json_encode([
                'entry' => 'entry.php',
                'binary' => '.phpc/bin/app',
            ], JSON_THROW_ON_ERROR)
        );

        $deploy = sys_get_temp_dir().'/phpc_deploy_out_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($deploy));
        $this->assertTrue(mkdir($deploy.'/templates', 0777, true));
        file_put_contents($deploy.'/templates/needle.html', 'deploy-tree');

        $runCwd = sys_get_temp_dir().'/phpc_deploy_cwd_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($runCwd));

        try {
            $phpc = realpath($repoRoot.'/phpc');
            $this->assertNotFalse($phpc);
            $env = $this->llvmProcessEnv($repoRoot);
            $binary = $project.'/.phpc/bin/app';

            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open([$phpc, 'build', '--project', $project], $descriptorSpec, $pipes, $repoRoot, $env);
            $this->assertIsResource($proc);
            fclose($pipes[0]);
            $buildErr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), trim($buildErr !== false ? $buildErr : ''));
            $this->assertFileExists($binary);

            $runEnv = $env;
            $runEnv[DeployRoot::ENV] = $deploy;
            $run = proc_open([$binary], $descriptorSpec, $pipes, $runCwd, $runEnv);
            $this->assertIsResource($run);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $runErr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($run), trim($runErr !== false ? $runErr : ''));
            $expected = $deploy.'/templates/needle.html';
            $this->assertStringContainsString($expected, $stdout !== false ? $stdout : '');
        } finally {
            $binary = $project.'/.phpc/bin/app';
            if (is_file($binary)) {
                @unlink($binary);
            }
            $this->removeTree($project);
            $this->removeTree($deploy);
            $this->removeTree($runCwd);
        }
    }

    /**
     * @return array<string, string>
     */
    private function llvmProcessEnv(string $repoRoot): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        return $env;
    }

    private function removeTree(string $dir): void
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
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private static function isLlvmReady(): bool
    {
        if (null !== self::$llvmReady) {
            return self::$llvmReady;
        }
        self::$llvmReady = LlvmToolchain::isReady(dirname(__DIR__, 2));

        return self::$llvmReady;
    }
}
