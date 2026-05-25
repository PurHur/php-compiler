<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\CgiAotDriver;
use PHPCompiler\Web\ProjectDeploy;

/**
 * phpc deploy dist layout (issue #609).
 */
final class PhpcDeployTest extends TestCase
{
    public function testDeployCopiesBinaryManifestAndOptionalTrees(): void
    {
        $project = sys_get_temp_dir().'/phpc_deploy_unit_'.bin2hex(random_bytes(6));
        $out = sys_get_temp_dir().'/phpc_deploy_out_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($project, 0777, true));
        $this->assertTrue(mkdir($project.'/.phpc/bin', 0777, true));
        $this->assertTrue(mkdir($project.'/public', 0777, true));
        $this->assertTrue(mkdir($project.'/assets', 0777, true));
        $this->assertTrue(mkdir($project.'/templates', 0777, true));

        try {
            file_put_contents($project.'/.phpc/bin/app', "#!/bin/sh\necho deployed\n");
            chmod($project.'/.phpc/bin/app', 0755);
            file_put_contents($project.'/public/index.php', '<?php echo "public";');
            file_put_contents($project.'/assets/app.css', 'body{}');
            file_put_contents($project.'/templates/layout.php', '<html></html>');
            file_put_contents(
                $project.'/phpc.json',
                json_encode([
                    'entry' => 'public/index.php',
                    'binary' => '.phpc/bin/app',
                    'public' => 'public',
                    'assets' => 'assets',
                ], JSON_THROW_ON_ERROR)
            );

            $errors = ProjectDeploy::deploy($project, $out, false);
            $this->assertSame([], $errors, implode('; ', $errors));

            $this->assertFileExists($out.'/bin/app');
            $this->assertFileIsReadable($out.'/bin/app');
            $this->assertFileExists($out.'/phpc.json');
            $this->assertFileExists($out.'/public/index.php');
            $this->assertFileExists($out.'/assets/app.css');
            $this->assertFileExists($out.'/templates/layout.php');
            $this->assertFileExists($out.'/'.ProjectDeploy::README_DEPLOY);
            $this->assertFileExists($out.'/'.CgiAotDriver::WRAPPER_NAME);
            $this->assertTrue(is_executable($out.'/'.CgiAotDriver::WRAPPER_NAME));
            $readme = (string) file_get_contents($out.'/'.ProjectDeploy::README_DEPLOY);
            $this->assertStringContainsString('PHPC_DEPLOY_ROOT', $readme);
            $this->assertStringContainsString('PHP_COMPILER_SESSION_DIR', $readme);
            $this->assertStringContainsString('cgi-wrapper', $readme);
        } finally {
            $this->removeTree($project);
            $this->removeTree($out);
        }
    }

    public function testDeployRejectsPathTraversalInManifestDirs(): void
    {
        $project = sys_get_temp_dir().'/phpc_deploy_trav_'.bin2hex(random_bytes(6));
        $out = sys_get_temp_dir().'/phpc_deploy_trav_out_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($project, 0777, true));
        $this->assertTrue(mkdir($project.'/.phpc/bin', 0777, true));

        try {
            file_put_contents($project.'/.phpc/bin/app', "#!/bin/sh\necho ok\n");
            chmod($project.'/.phpc/bin/app', 0755);
            file_put_contents(
                $project.'/phpc.json',
                json_encode([
                    'entry' => 'entry.php',
                    'binary' => '.phpc/bin/app',
                    'public' => '../',
                ], JSON_THROW_ON_ERROR)
            );
            file_put_contents($project.'/entry.php', '<?php');

            $errors = ProjectDeploy::deploy($project, $out, false);
            $this->assertNotSame([], $errors);
            $this->assertStringContainsString('public', implode(' ', $errors));
        } finally {
            $this->removeTree($project);
            $this->removeTree($out);
        }
    }

    public function testPhpcDeployCliOnStaticWebExample(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $example = $repoRoot.'/examples/002-StaticWeb';
        $binary = $example.'/.phpc/bin/app';
        if (!is_file($binary)) {
            $this->markTestSkipped('examples/002-StaticWeb binary missing; run phpc build --project first');
        }

        $out = sys_get_temp_dir().'/phpc_deploy_static_'.bin2hex(random_bytes(6));
        try {
            $result = $this->runPhpc(['deploy', $example, '-o', $out]);
            $this->assertSame(0, $result['exit'], $result['stderr']);
            $this->assertFileExists($out.'/bin/app');
            $this->assertFileExists($out.'/phpc.json');
        } finally {
            $this->removeTree($out);
        }
    }

    /**
     * @param list<string> $args arguments after phpc.php
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpc(array $args): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge([PHP_BINARY, $repoRoot.'/bin/phpc.php'], $args);
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
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
        ];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
