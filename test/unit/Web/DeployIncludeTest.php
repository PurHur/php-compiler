<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Runtime;
use PHPCompiler\Web\DeployRoot;

/**
 * Runtime include via phpc_deploy_path() under PHPC_DEPLOY_ROOT (issue #623).
 */
final class DeployIncludeTest extends TestCase
{
    public function testVmIncludeReadsTemplateFromDeployRoot(): void
    {
        $project = sys_get_temp_dir().'/phpc_inc_proj_'.bin2hex(random_bytes(6));
        $deploy = sys_get_temp_dir().'/phpc_inc_deploy_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($project));
        $this->assertTrue(mkdir($project.'/templates', 0777, true));
        $this->assertTrue(mkdir($deploy));
        $this->assertTrue(mkdir($deploy.'/templates', 0777, true));

        file_put_contents($project.'/templates/marker.php', "<?php\n\$marker = 'compile-tree';\n");
        file_put_contents($deploy.'/templates/marker.php', "<?php\n\$marker = 'deploy-tree';\n");
        file_put_contents(
            $project.'/entry.php',
            "<?php\n\$marker = 'missing';\n"
            ."include phpc_deploy_path('templates', '".addslashes($project)."') . '/marker.php';\n"
            ."echo \$marker;\n"
        );

        $previous = getenv(DeployRoot::ENV);
        putenv(DeployRoot::ENV.'='.$deploy);
        try {
            $this->expectOutputString('deploy-tree');
            $runtime = new Runtime();
            $runtime->run($runtime->parseAndCompileFile($project.'/entry.php'));
        } finally {
            if (false === $previous) {
                putenv(DeployRoot::ENV);
            } else {
                putenv(DeployRoot::ENV.'='.$previous);
            }
            $this->removeTree($project);
            $this->removeTree($deploy);
        }
    }

    /**
     * @group llvm
     * @group aot
     * @group aot-lint
     */
    public function testDeployIncludeFixtureAotLint(): void
    {
        if (!\PHPCompiler\LlvmToolchain::isReady(dirname(__DIR__, 3))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $entry = realpath(__DIR__.'/../../fixtures/aot/cases/deploy_include_template/entry.php');
        $this->assertNotFalse($entry);
        $repoRoot = dirname(__DIR__, 3);
        $cmd = array_merge(
            \PHPCompiler\LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-l', $entry]
        );
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), trim($stderr !== false ? $stderr : ''));
    }

    public function testCompileFixtureSetsDeployIncludePaths(): void
    {
        $entry = realpath(__DIR__.'/../../fixtures/aot/cases/deploy_include_template/entry.php');
        $this->assertNotFalse($entry);
        $runtime = new Runtime();
        $script = $runtime->parser->parse((string) file_get_contents($entry), $entry);
        $runtime->preprocessor->traverse($script);
        $parsed = false;
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof \PHPCfg\Op\Expr\Include_) {
                continue;
            }
            $spec = ConstStringFolder::tryParseDeployInclude($script->main->cfg, $child->expr, $entry);
            $this->assertNotNull($spec, 'tryParseDeployInclude must recognize fixture include expr');
            $parsed = true;
            break;
        }
        $this->assertTrue($parsed, 'expected include in fixture CFG');

        $block = $runtime->compile($script);
        $this->assertNotNull($block);
        $this->assertNotEmpty($block->deployIncludePaths, 'expected deployIncludePaths on compiled entry');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
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
}
