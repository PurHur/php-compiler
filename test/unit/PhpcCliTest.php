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
