<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\AOT\ProjectGraph;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/504
 */
final class ProjectGraphTest extends TestCase
{
    public function testResolveMiniWebAppListsEntryManifestAndConfig(): void
    {
        $dir = dirname(__DIR__, 2).'/examples/003-MiniWebApp';
        $result = ProjectGraph::resolve($dir);
        $this->assertSame([], $result['errors'], implode("\n", $result['errors']));
        $joined = implode("\n", $result['files']);
        $this->assertStringContainsString('public/index.php', $joined);
        $this->assertStringContainsString('src/Router.php', $joined);
        $this->assertStringContainsString('config.php', $joined);
        // Method-body template includes are JIT-inlined, not AOT bundle units (#739, #878).
        $this->assertStringNotContainsString('templates/layout.php', $joined);
    }

    public function testDryRunCliPrintsMiniWebAppFiles(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $result = $this->runPhpcBuild(['--project', '--dry-run', $repoRoot.'/examples/003-MiniWebApp'], $repoRoot);
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('public/index.php', $result['stdout']);
        $this->assertStringContainsString('config.php', $result['stdout']);
        $this->assertStringNotContainsString('templates/layout.php', $result['stdout']);
    }

    public function testRemovingManifestIncludeRequiredByEntryFails(): void
    {
        $dir = sys_get_temp_dir().'/phpc_graph_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/src', 0777, true));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents($dir.'/src/Router.php', "<?php\n");
            file_put_contents(
                $dir.'/public/index.php',
                "<?php\nrequire __DIR__ . '/../src/Router.php';\n"
            );
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'public/index.php',
                    'binary' => '.phpc/bin/app',
                    'includes' => [],
                ], JSON_THROW_ON_ERROR)
            );
            $result = ProjectGraph::resolve($dir);
            $this->assertNotSame([], $result['errors']);
            $this->assertStringContainsString('includes[] must list', implode("\n", $result['errors']));
            $this->assertStringContainsString('Router.php', implode("\n", $result['errors']));
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testMissingManifestIncludePathFails(): void
    {
        $dir = sys_get_temp_dir().'/phpc_graph_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents($dir.'/entry.php', '<?php');
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'entry.php',
                    'binary' => '.phpc/bin/app',
                    'includes' => ['missing.php'],
                ], JSON_THROW_ON_ERROR)
            );
            $result = ProjectGraph::resolve($dir);
            $this->assertContains('includes path not found: missing.php', $result['errors']);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testDuplicateManifestIncludesFails(): void
    {
        $dir = sys_get_temp_dir().'/phpc_graph_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents($dir.'/helper.php', '<?php');
            file_put_contents($dir.'/entry.php', '<?php');
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'entry.php',
                    'binary' => '.phpc/bin/app',
                    'includes' => ['helper.php', 'helper.php'],
                ], JSON_THROW_ON_ERROR)
            );
            $result = ProjectGraph::resolve($dir);
            $this->assertStringContainsString('duplicate path', implode("\n", $result['errors']));
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testPsr4StaticDiscoveryAddsReferencedClassFile(): void
    {
        $dir = dirname(__DIR__, 2).'/test/fixtures/aot/projects/psr4_static';
        $result = ProjectGraph::resolve($dir);
        $this->assertSame([], $result['errors'], implode("\n", $result['errors']));
        $joined = implode("\n", $result['files']);
        $this->assertStringContainsString('src/Greeter.php', $joined);
        $this->assertStringContainsString('public/index.php', $joined);
    }

    public function testPsr4StaticDiscoveryReportsMissingClass(): void
    {
        $dir = sys_get_temp_dir().'/phpc_graph_psr4_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        $this->assertTrue(mkdir($dir.'/src', 0777, true));
        try {
            file_put_contents(
                $dir.'/public/index.php',
                <<<'PHP'
<?php

declare(strict_types=1);

echo (new App\Missing())->run();
PHP
            );
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'public/index.php',
                    'binary' => '.phpc/bin/app',
                    'autoload' => ['psr-4' => ['App\\' => 'src/']],
                ], JSON_THROW_ON_ERROR)
            );
            $result = ProjectGraph::resolve($dir);
            $this->assertNotSame([], $result['errors']);
            $this->assertStringContainsString('autoload: unresolved class App\\Missing', implode("\n", $result['errors']));
            $this->assertStringContainsString('expected src/Missing.php', implode("\n", $result['errors']));
        } finally {
            $this->removeTree($dir);
        }
    }

    /**
     * @param list<string> $args arguments after phpc build
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpcBuild(array $args, string $cwd): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [$repoRoot.'/bin/phpc.php', 'build', ...$args]);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
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
                unlink($path);
            }
        }
        rmdir($dir);
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
