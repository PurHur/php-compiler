<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPUnit\Framework\TestCase;

/**
 * phpc.json schema and path validation (issue #263).
 */
final class ProjectManifestTest extends TestCase
{
    public function testResolveEntryAndBinaryOutputFromManifest(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents($dir.'/example.php', '<?php');
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'example.php',
                    'binary' => '.phpc/bin/app',
                ], JSON_THROW_ON_ERROR)
            );
            $this->assertSame($dir, ProjectManifest::resolveProjectDir($dir));
            $entry = ProjectManifest::resolveEntryPath($dir);
            $this->assertNotNull($entry);
            $this->assertStringEndsWith('/example.php', $entry);
            $binary = ProjectManifest::resolveBinaryOutputPath($dir);
            $this->assertNotNull($binary);
            $this->assertStringEndsWith('/.phpc/bin/app', $binary);
            $this->assertSame([], ManifestValidator::validateForBuild($dir));
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testResolveIncludePathsFromManifest(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/src', 0777, true));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents($dir.'/src/helpers.php', '<?php function helper(): int { return 1; }');
            file_put_contents($dir.'/public/index.php', '<?php');
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'public/index.php',
                    'binary' => '.phpc/bin/app',
                    'includes' => ['src/helpers.php'],
                ], JSON_THROW_ON_ERROR)
            );
            $paths = ProjectManifest::resolveIncludePaths($dir);
            $this->assertCount(1, $paths);
            $this->assertStringEndsWith('/src/helpers.php', $paths[0]);
            $this->assertSame([], ManifestValidator::validateForBuild($dir));
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testValidateForBuildRejectsMissingInclude(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents($dir.'/example.php', '<?php');
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'example.php',
                    'binary' => '.phpc/bin/app',
                    'includes' => ['missing.php'],
                ], JSON_THROW_ON_ERROR)
            );
            $errors = ManifestValidator::validateForBuild($dir);
            $this->assertContains('includes path not found: missing.php', $errors);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testValidateForBuildRequiresEntry(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents($dir.'/phpc.json', '{"binary": ".phpc/bin/app"}');
            $errors = ManifestValidator::validateForBuild($dir);
            $this->assertContains('missing required key: entry', $errors);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testValidateRejectsMissingBinaryPath(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents($dir.'/phpc.json', '{"binary": "missing"}');
            $errors = ManifestValidator::validate($dir);
            $this->assertContains('binary path not found: missing', $errors);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testValidateAcceptsExistingBinaryAndEntry(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/.phpc/bin', 0777, true));
        try {
            touch($dir.'/.phpc/bin/app');
            file_put_contents($dir.'/index.php', '<?php');
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'index.php',
                    'binary' => '.phpc/bin/app',
                ], JSON_THROW_ON_ERROR)
            );
            $this->assertSame([], ManifestValidator::validate($dir));
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testValidateRejectsUnknownKeys(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/.phpc/bin', 0777, true));
        try {
            touch($dir.'/.phpc/bin/app');
            file_put_contents($dir.'/phpc.json', '{"binary": ".phpc/bin/app", "typo": true}');
            $errors = ManifestValidator::validate($dir);
            $this->assertContains('unknown key in phpc.json: typo', $errors);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testResolvePublicDirUsesManifestPublicKey(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents($dir.'/public/index.php', '<?php');
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'public/index.php',
                    'binary' => '.phpc/bin/app',
                    'public' => 'public',
                ], JSON_THROW_ON_ERROR)
            );
            $public = ProjectManifest::resolvePublicDir($dir);
            $this->assertSame(realpath($dir.'/public'), $public);
            $this->assertSame(realpath($dir.'/public'), ProjectManifest::resolvePublicDir($dir.'/public'));
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testResolvePublicDirWithoutPublicKeyKeepsStartDir(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents($dir.'/example.php', '<?php');
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'example.php',
                    'binary' => '.phpc/bin/app',
                ], JSON_THROW_ON_ERROR)
            );
            $this->assertSame(realpath($dir), ProjectManifest::resolvePublicDir($dir));
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testValidatePublicRequiresIndexPhp(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/.phpc/bin', 0777, true));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            touch($dir.'/.phpc/bin/app');
            file_put_contents($dir.'/phpc.json', '{"binary": ".phpc/bin/app", "public": "public"}');
            $errors = ManifestValidator::validate($dir);
            $this->assertContains('missing public/index.php under public', $errors);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testPhpcValidateManifestCliMatchesAcceptanceCriteria(): void
    {
        $dir = sys_get_temp_dir().'/phpc_manifest_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents($dir.'/phpc.json', '{"binary": "missing"}');
            $result = $this->runPhpcValidateManifest($dir);
            $this->assertSame(1, $result['exit']);
            $this->assertStringContainsString('binary path not found: missing', $result['stderr']);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testInitScaffoldPassesValidateManifestAfterTouchBinary(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $dir = sys_get_temp_dir().'/phpc_init_validate_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            $init = $this->runPhpc(['init', $dir], $dir, $repoRoot);
            $this->assertSame(0, $init['exit'], $init['stderr']);
            $this->assertTrue(mkdir($dir.'/.phpc/bin', 0777, true));
            touch($dir.'/.phpc/bin/app');
            $validate = $this->runPhpc(['validate-manifest', $dir], $dir, $repoRoot);
            $this->assertSame(0, $validate['exit'], $validate['stderr']."\n".$validate['stdout']);
            $this->assertStringContainsString('phpc.json OK', $validate['stdout']);
        } finally {
            $this->removeTree($dir);
        }
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpcValidateManifest(string $dir): array
    {
        $repoRoot = dirname(__DIR__, 3);

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
