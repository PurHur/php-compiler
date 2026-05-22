<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Fast CI gate: shipped example phpc.json manifests validate via phpc validate-manifest (issue #654).
 *
 * @see https://github.com/PurHur/php-compiler/issues/263
 */
final class ExamplesManifestTest extends TestCase
{
    private const SHIPPED_EXAMPLES = [
        '001-SimpleWeb',
        '002-StaticWeb',
        '003-MiniWebApp',
        '004-ApiJson',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideShippedExampleDirs(): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cases = [];
        foreach (self::SHIPPED_EXAMPLES as $name) {
            $cases[$name] = [$repoRoot.'/examples/'.$name];
        }

        return $cases;
    }

    /**
     * @dataProvider provideShippedExampleDirs
     */
    public function testShippedExampleValidateManifest(string $exampleDir): void
    {
        $this->assertDirectoryExists($exampleDir);
        $manifestPath = $exampleDir.'/phpc.json';
        $this->assertFileExists($manifestPath, 'missing phpc.json under '.$exampleDir);

        $createdStub = $this->ensureStubBinary($exampleDir);
        try {
            $result = $this->runPhpcValidateManifest($exampleDir);
            $this->assertSame(
                0,
                $result['exit'],
                $manifestPath.":\n".$result['stderr']."\n".$result['stdout']
            );
            $this->assertStringContainsString('phpc.json OK', $result['stdout']);
        } finally {
            if ($createdStub) {
                $this->removeStubBinary($exampleDir);
            }
        }
    }

    public function testBrokenIncludesFailsValidateManifest(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $source = $repoRoot.'/examples/003-MiniWebApp';
        $this->assertDirectoryExists($source);

        $dir = sys_get_temp_dir().'/phpc_manifest_bad_'.bin2hex(random_bytes(6));
        $this->copyTree($source, $dir);
        $createdStub = $this->ensureStubBinary($dir);
        $this->assertTrue($createdStub);

        $manifestPath = $dir.'/phpc.json';
        $raw = file_get_contents($manifestPath);
        $this->assertNotFalse($raw);
        $data = json_decode($raw, true);
        $this->assertIsArray($data);
        $data['includes'] = ['src/NoSuchFile.php'];
        file_put_contents($manifestPath, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        try {
            $result = $this->runPhpcValidateManifest($dir);
            $this->assertSame(1, $result['exit']);
            $this->assertStringContainsString('phpc.json:', $result['stderr']);
            $this->assertStringContainsString(
                'includes path not found: src/NoSuchFile.php',
                $result['stderr']
            );
        } finally {
            $this->removeTree($dir);
        }
    }

    private function ensureStubBinary(string $projectDir): bool
    {
        $binaryPath = $projectDir.'/.phpc/bin/app';
        if (is_file($binaryPath)) {
            return false;
        }
        $binDir = dirname($binaryPath);
        if (!is_dir($binDir)) {
            $this->assertTrue(mkdir($binDir, 0777, true));
        }
        $this->assertTrue(touch($binaryPath));

        return true;
    }

    private function removeStubBinary(string $projectDir): void
    {
        $binaryPath = $projectDir.'/.phpc/bin/app';
        if (is_file($binaryPath)) {
            @unlink($binaryPath);
        }
        $binDir = dirname($binaryPath);
        if (is_dir($binDir) && [] === array_diff(scandir($binDir) ?: [], ['.', '..'])) {
            @rmdir($binDir);
        }
        $phpcDir = dirname($binDir);
        if (is_dir($phpcDir) && [] === array_diff(scandir($phpcDir) ?: [], ['.', '..'])) {
            @rmdir($phpcDir);
        }
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpcValidateManifest(string $dir): array
    {
        $repoRoot = dirname(__DIR__, 2);

        return $this->runPhpc(['validate-manifest', $dir], $dir, $repoRoot);
    }

    /**
     * @param list<string> $phpcArgs
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpc(array $phpcArgs, string $cwd, string $repoRoot): array
    {
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

    private function copyTree(string $source, string $dest): void
    {
        $this->assertTrue(mkdir($dest));
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest.DIRECTORY_SEPARATOR.$iterator->getSubPathname();
            if ($item->isDir()) {
                $this->assertTrue(mkdir($target));
            } else {
                $this->assertTrue(copy($item->getPathname(), $target));
            }
        }
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
