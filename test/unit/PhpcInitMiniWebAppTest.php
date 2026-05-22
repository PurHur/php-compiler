<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * phpc init --profile miniwebapp scaffold (issue #632).
 */
final class PhpcInitMiniWebAppTest extends TestCase
{
    public function testInitMiniWebAppProfileLintAllClean(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $work = sys_get_temp_dir().'/phpc_init_miniwebapp_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($work));

        try {
            $init = $this->runPhpc(['init', '--profile', 'miniwebapp', $work], $work);
            $this->assertSame(0, $init['exit'], $init['stderr']);

            $expected = [
                'phpc.json',
                'config.php',
                'public/index.php',
                'src/Router.php',
                'templates/layout.php',
                'templates/home.php',
                'templates/hello.php',
                'templates/contact.php',
                'templates/thankyou.php',
                'assets/style.css',
                'README.md',
            ];
            foreach ($expected as $relative) {
                $this->assertFileExists($work.'/'.$relative, $relative);
            }

            $manifest = json_decode((string) file_get_contents($work.'/phpc.json'), true);
            $this->assertIsArray($manifest);
            $this->assertSame('public/index.php', $manifest['entry'] ?? null);
            $this->assertSame('public', $manifest['public'] ?? null);
            $this->assertSame('assets', $manifest['assets'] ?? null);
            $this->assertSame(['src/Router.php', 'config.php'], $manifest['includes'] ?? null);

            // phpc lint subprocess cwd is always repo root; pass absolute tree path (see PhpcLintProjectTest).
            $lint = $this->runPhpc(['lint', '--all', $work], $repoRoot);
            $this->assertSame(0, $lint['exit'], $lint['stderr']."\n".$lint['stdout']);

            $second = $this->runPhpc(['init', '--profile', 'miniwebapp', $work], $work);
            $this->assertNotSame(0, $second['exit']);
            $this->assertStringContainsString('--force', $second['stderr']);
        } finally {
            $this->removeTree($work);
        }
    }

    public function testInitRejectsUnknownProfile(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $work = sys_get_temp_dir().'/phpc_init_badprofile_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($work));

        try {
            $init = $this->runPhpc(['init', '--profile', 'nosuch', $work], $repoRoot);
            $this->assertNotSame(0, $init['exit']);
            $this->assertStringContainsString('unknown profile', $init['stderr']);
        } finally {
            $this->removeTree($work);
        }
    }

    /**
     * @param list<string> $phpcArgs
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpc(array $phpcArgs, string $cwd): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', ...$phpcArgs]
        );
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
                @unlink($path);
            }
        }
        @rmdir($dir);
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
