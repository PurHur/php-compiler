<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — coalesce FQCN defaults expand ProjectGraph; AOT `new $merged['k']` dispatch runs.
 *
 * @group aot
 */
final class Issue36382FastrouteCoalesceDiscoveryAotTest extends TestCase
{
    public function testComposerShapedCoalesceProjectDispatches(): void
    {
        $repo = dirname(__DIR__, 2);
        if (!\PHPCompiler\LlvmToolchain::isReady($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $dir = sys_get_temp_dir().'/phpc_fr_coal_proj_'.getmypid().'_'.bin2hex(random_bytes(3));
        $this->assertTrue(mkdir($dir.'/vendor/nikic/fast-route/src', 0777, true));
        $this->assertTrue(mkdir($dir.'/vendor/composer', 0777, true));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            $src = $dir.'/vendor/nikic/fast-route/src';
            file_put_contents(
                $src.'/RouteCollector.php',
                "<?php\nnamespace FastRoute;\nclass RouteCollector {\n"
                ."    public function __construct(\$p = null, \$g = null) {}\n"
                ."    public function addRoute(\$m, \$r, \$h): void {}\n"
                ."    public function getData(): array { return [[], []]; }\n"
                ."}\n"
            );
            file_put_contents(
                $src.'/Dispatcher.php',
                "<?php\nnamespace FastRoute;\nclass Dispatcher {\n"
                ."    public function __construct(public array \$data) {}\n"
                ."    public function dispatch(\$m, \$u): array { return [1, 'hello_id', []]; }\n"
                ."}\n"
            );
            // Patched simpleDispatcher shape: coalesce into \$merged then varclass new.
            file_put_contents(
                $src.'/functions.php',
                "<?php\nnamespace FastRoute;\n"
                ."function simpleDispatcher(callable \$cb, array \$options = []) {\n"
                ."    \$merged = [\n"
                ."        'routeCollector' => \$options['routeCollector'] ?? 'FastRoute\\\\RouteCollector',\n"
                ."        'dispatcher' => \$options['dispatcher'] ?? 'FastRoute\\\\Dispatcher',\n"
                ."    ];\n"
                ."    \$rc = new \$merged['routeCollector']();\n"
                ."    \$cb(\$rc);\n"
                ."    return new \$merged['dispatcher'](\$rc->getData());\n"
                ."}\n"
            );
            $vendorDir = $dir.'/vendor';
            file_put_contents(
                $dir.'/vendor/composer/autoload_psr4.php',
                "<?php\nreturn ['FastRoute\\\\' => [".var_export($src, true)."]];\n"
            );
            file_put_contents(
                $dir.'/vendor/composer/autoload_classmap.php',
                "<?php\nreturn [];\n"
            );
            file_put_contents(
                $dir.'/vendor/composer/autoload_files.php',
                "<?php\nreturn ['x' => ".var_export($src.'/functions.php', true)."];\n"
            );
            file_put_contents($dir.'/vendor/autoload.php', "<?php\n");
            file_put_contents(
                $dir.'/public/index.php',
                "<?php\nuse function FastRoute\\simpleDispatcher;\n"
                ."require __DIR__.'/../vendor/autoload.php';\n"
                ."echo \"START\\n\";\n"
                ."\$d = simpleDispatcher(function (\$r) { \$r->addRoute('GET', '/hello', 'hello_id'); });\n"
                ."\$res = \$d->dispatch('GET', '/hello');\n"
                ."echo \$res[0], ':', \$res[1], \"\\n\";\n"
                ."echo \"OK\\n\";\n"
            );
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'public/index.php',
                    'binary' => '.phpc/bin/app',
                    'autoload' => 'composer',
                ], JSON_THROW_ON_ERROR)
            );

            $graph = \PHPCompiler\AOT\ProjectGraph::resolve($dir);
            $this->assertSame([], $graph['errors'], implode("\n", $graph['errors']));
            $joined = implode("\n", $graph['files']);
            $this->assertStringContainsString('RouteCollector.php', $joined);
            $this->assertStringContainsString('Dispatcher.php', $joined);

            $bin = $dir.'/.phpc/bin/app';
            $env = $_ENV;
            \PHPCompiler\LlvmToolchain::applyProcessEnv($env, $repo);
            $env['PHP_COMPILER_CACHE'] = '0';
            $env['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = sys_get_temp_dir()
                .'/phpc-helper-36382-frcoal-'.getmypid();
            $cmd = sprintf(
                'php -d memory_limit=2048M %s build --project %s 2>&1',
                escapeshellarg($repo.'/bin/phpc.php'),
                escapeshellarg($dir)
            );
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes, $repo, $env);
            $this->assertIsResource($proc);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $ec = proc_close($proc);
            $this->assertSame(0, $ec, trim((string) $stdout."\n".$stderr));
            $this->assertFileExists($bin);

            $runLines = [];
            exec(escapeshellarg($bin).' 2>&1', $runLines, $runEc);
            $this->assertSame(0, $runEc, implode("\n", $runLines));
            $trimmed = array_values(array_filter(
                array_map('trim', $runLines),
                static fn (string $l): bool => '' !== $l && !str_starts_with($l, 'PHP Warning:')
            ));
            $this->assertSame(['START', '1:hello_id', 'OK'], $trimmed);
        } finally {
            $this->removeTree($dir);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
