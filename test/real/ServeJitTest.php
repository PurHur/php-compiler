<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Integration tests for bin/serve-jit.php / phpc serve --jit (issue #207).
 *
 * @group serve
 * @group llvm
 * @group jit
 */
final class ServeJitTest extends TestCase
{
    private string $repoRoot;

    /** @var list<string> */
    private array $phpCmd = [];

    protected function setUp(): void
    {
        if (false !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS') && '' !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS')) {
            $this->markTestSkipped('PHP_COMPILER_SKIP_SERVE_TESTS is set');
        }
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — run script/install-llvm9.sh');
        }
        if (!self::jitProbeGreen($this->repoRoot)) {
            $this->markTestSkipped('JIT MCJIT probe failed — bin/jit.php not runnable (#98)');
        }
        $this->phpCmd = self::phpCommand();
    }

    public function testJitServeMatchesVmFor001SimpleWeb(): void
    {
        $docroot = $this->repoRoot.'/examples/001-SimpleWeb';
        $this->assertDirectoryExists($docroot);
        $path = '/example.php?name=Dev';

        $vmResponse = $this->httpGet($docroot, $path, 'serve.php');
        $jitResponse = $this->httpGet($docroot, $path, 'serve-jit.php');

        $this->assertStringContainsString('HTTP/1.1 200', $vmResponse);
        $this->assertStringContainsString('HTTP/1.1 200', $jitResponse);
        $this->assertSame(
            $this->normalizeBody($this->responseBody($vmResponse)),
            $this->normalizeBody($this->responseBody($jitResponse))
        );
    }

    public function testJitServeCachesCompiledScriptAcrossRequests(): void
    {
        $docroot = $this->makeDocroot([
            'counter.php' => <<<'PHP'
<?php
header('Content-Type: text/plain; charset=UTF-8');
static $hits = 0;
echo ++$hits;
PHP,
        ]);

        $port = $this->findFreePort();
        $addr = "127.0.0.1:{$port}";
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = array_merge($this->phpCmd, [$this->repoRoot.'/bin/serve-jit.php', $addr, $docroot]);
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $this->baseEnv());
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->waitForPort($port);

        $first = trim($this->responseBody($this->requestPath($port, '/counter.php')));
        $second = trim($this->responseBody($this->requestPath($port, '/counter.php')));

        proc_terminate($proc);
        proc_close($proc);

        $this->assertSame('1', $first);
        $this->assertSame('2', $second);
    }

    private function requestPath(int $port, string $path): string
    {
        $conn = fsockopen('127.0.0.1', $port);
        $this->assertIsResource($conn);
        fwrite($conn, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($conn);
        fclose($conn);

        return $response !== false ? $response : '';
    }

    private function httpGet(string $docroot, string $path, string $serveScript): string
    {
        $port = $this->findFreePort();
        $addr = "127.0.0.1:{$port}";
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = array_merge($this->phpCmd, [$this->repoRoot.'/bin/'.$serveScript, $addr, $docroot]);
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $this->baseEnv());
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->waitForPort($port);

        $conn = fsockopen('127.0.0.1', $port);
        $this->assertIsResource($conn);
        fwrite($conn, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($conn);
        fclose($conn);

        proc_terminate($proc);
        proc_close($proc);

        return $response !== false ? $response : '';
    }

    private function waitForPort(int $port): void
    {
        $deadline = microtime(true) + 5.0;
        $ready = false;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (false !== $conn) {
                $ready = true;
                fclose($conn);
                break;
            }
            usleep(50_000);
        }
        $this->assertTrue($ready, 'serve did not become ready');
    }

    private function responseBody(string $response): string
    {
        $parts = preg_split("/\r\n\r\n|\n\n/", $response, 2);

        return $parts[1] ?? '';
    }

    private function normalizeBody(string $body): string
    {
        return preg_replace('/\s+/', ' ', trim($body)) ?? trim($body);
    }

    private function makeDocroot(array $files): string
    {
        $dir = sys_get_temp_dir().'/phpc_serve_jit_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir));
        foreach ($files as $name => $contents) {
            $path = $dir.'/'.$name;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            file_put_contents($path, $contents);
        }

        return $dir;
    }

    private function findFreePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $name = stream_socket_get_name($server, false);
        fclose($server);
        $this->assertIsString($name);
        $this->assertMatchesRegularExpression('#:(\d+)$#', $name, $name);

        return (int) preg_replace('#^.*:#', '', $name);
    }

    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            $cmd = preg_split('/\s+/', $phpEnv);
        } else {
            $cmd = [PHP_BINARY];
        }
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

    private static function jitProbeGreen(string $root): bool
    {
        static $cached = null;
        if (null !== $cached) {
            return $cached;
        }
        $probe = $root.'/script/jit-runtime-probe.php';
        if (!is_file($probe)) {
            $cached = false;

            return false;
        }
        $cmd = array_merge(self::phpCommand(), [$probe]);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
        if (!is_resource($proc)) {
            $cached = false;

            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $cached = 0 === proc_close($proc);

        return $cached;
    }
}
