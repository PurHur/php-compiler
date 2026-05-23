<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcRun;
use PHPUnit\Framework\TestCase;

/**
 * phpc run --project with CGI env for AOT binaries (issue #774).
 */
final class PhpcRunCgiEnvTest extends TestCase
{
    public function testLoadCgiEnvFileSkipsCommentsAndExport(): void
    {
        $path = dirname(__DIR__).'/fixtures/cgi-env/simpleweb-name-dev.env';
        $pairs = PhpcRun::loadCgiEnvFile($path);
        $this->assertContains('QUERY_STRING=name=Dev', $pairs);
        $this->assertContains('REQUEST_METHOD=GET', $pairs);
        $this->assertNotContains('', $pairs);
    }

    public function testFinalizeExitPreservesBinaryCodeWhenStdoutNonempty(): void
    {
        $this->assertSame(0, PhpcRun::finalizeExit(0, '<h1>Hello</h1>', false));
        $this->assertSame(0, PhpcRun::finalizeExit(0, '<h1>Hello</h1>', true));
    }

    public function testFinalizeExitFailsOnEmptyStdoutWhenRequired(): void
    {
        $this->assertSame(PhpcRun::EXIT_EMPTY_STDOUT, PhpcRun::finalizeExit(0, '', true));
        $this->assertSame(0, PhpcRun::finalizeExit(0, '', false));
    }

    public function testRunProjectWithoutBinaryExitsNonZero(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $project = $repoRoot.'/examples/001-SimpleWeb';
        $binary = $project.'/.phpc/bin/app';
        $hadBinary = is_file($binary);
        if ($hadBinary) {
            rename($binary, $binary.'.bak-phpc774');
        }
        try {
            $result = $this->runPhpc([
                'run',
                '--project',
                $project,
                '--cgi-env',
                'QUERY_STRING=name=Dev',
            ]);
            $this->assertNotSame(0, $result['exit']);
            $this->assertStringContainsString('binary not found', $result['stderr']);
            $this->assertStringContainsString('phpc build --project', $result['stderr']);
        } finally {
            if ($hadBinary) {
                rename($binary.'.bak-phpc774', $binary);
            }
        }
    }

    public function testCgiEnvRequiresProject(): void
    {
        $result = $this->runPhpc(['run', '--cgi-env', 'QUERY_STRING=x']);
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('require --project', $result['stderr']);
    }

    /**
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function testRunProjectSimpleWebWithCgiEnv(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $project = $repoRoot.'/examples/001-SimpleWeb';
        $build = $this->runPhpc(['build', '--project', $project]);
        $this->assertSame(0, $build['exit'], 'build failed: '.$build['stderr']);

        $result = $this->runPhpc([
            'run',
            '--project',
            $project,
            '--cgi-env',
            'QUERY_STRING=name=Dev',
            '--cgi-env',
            'REQUEST_METHOD=GET',
        ]);
        $this->assertSame(0, $result['exit'], trim($result['stderr']."\n".$result['stdout']));
        $this->assertStringContainsString('Hello Dev', $result['stdout']);
    }

    /**
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function testRunProjectSimpleWebWithCgiEnvFile(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $project = $repoRoot.'/examples/001-SimpleWeb';
        $binary = $project.'/.phpc/bin/app';
        if (!is_file($binary)) {
            $build = $this->runPhpc(['build', '--project', $project]);
            $this->assertSame(0, $build['exit'], 'build failed: '.$build['stderr']);
        }

        $envFile = dirname(__DIR__).'/fixtures/cgi-env/simpleweb-name-dev.env';
        $result = $this->runPhpc([
            'run',
            '--project',
            $project,
            '--cgi-env-file',
            $envFile,
        ]);
        $this->assertSame(0, $result['exit'], trim($result['stderr']."\n".$result['stdout']));
        $this->assertStringContainsString('Hello Dev', $result['stdout']);
    }

    /**
     * @param list<string> $phpcArgs
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpc(array $phpcArgs): array
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
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
        ];
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
