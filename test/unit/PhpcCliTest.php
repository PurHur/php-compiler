<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Unified phpc CLI dispatcher (issue #159).
 */
final class PhpcCliTest extends TestCase
{
    public function testHelpListsSubcommands(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [$repoRoot.'/bin/phpc.php', 'help']);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $this->assertStringContainsString('phpc serve', $out !== false ? $out : '');
        $this->assertStringContainsString('phpc serve --aot', $out !== false ? $out : '');
        $this->assertStringContainsString('phpc run', $out !== false ? $out : '');
        $this->assertStringContainsString('phpc build', $out !== false ? $out : '');
        $this->assertStringContainsString('phpc test', $out !== false ? $out : '');
        $this->assertStringContainsString('phpc lint', $out !== false ? $out : '');
        $this->assertStringContainsString('-q', $out !== false ? $out : '');
        $this->assertStringContainsString('$_GET', $out !== false ? $out : '');
    }

    public function testRunSimpleWebWithQueryFlag(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/examples/001-SimpleWeb/example.php';
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', 'run', '-q', 'name=Dev', $script]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, $err !== false ? $err : '');
        $this->assertStringContainsString('Hello Dev', $out !== false ? $out : '');
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
