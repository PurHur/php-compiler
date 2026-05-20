<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for phpc serve --aot (issue #213).
 *
 * @group llvm
 * @group serve
 */
final class ServeAotTest extends TestCase
{
    private string $repoRoot;

    /** @var list<string> */
    private array $phpCmd = [];

    private static ?bool $llvmReady = null;

    protected function setUp(): void
    {
        if (false !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS') && '' !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS')) {
            $this->markTestSkipped('PHP_COMPILER_SKIP_SERVE_TESTS is set');
        }
        if (!self::isLlvmReady()) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->repoRoot = dirname(__DIR__, 2);
        $this->phpCmd = self::phpCommand();
    }

    public function testServeAotPopulatesScriptFilename(): void
    {
        $docroot = $this->makeDocroot([
            'script.php' => <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_SERVER['SCRIPT_FILENAME'];
PHP,
        ]);
        $script = realpath($docroot.'/script.php');
        $this->assertNotFalse($script);
        $binaryDir = sys_get_temp_dir().'/phpc_serve_aot_sf_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($binaryDir));
        $binary = $binaryDir.'/app';
        $this->compileExample($docroot.'/script.php', $binary);
        $response = $this->httpGetAot($docroot, $binary, '/script.php');
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString($script, $response);
        @unlink($binary);
        @rmdir($binaryDir);
    }

    public function testServeAotPopulatesDocumentRoot(): void
    {
        $docroot = $this->makeDocroot([
            'docroot.php' => <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_SERVER['DOCUMENT_ROOT'];
PHP,
        ]);
        $resolved = realpath($docroot);
        $this->assertNotFalse($resolved);
        $binaryDir = sys_get_temp_dir().'/phpc_serve_aot_dr_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($binaryDir));
        $binary = $binaryDir.'/app';
        $this->compileExample($docroot.'/docroot.php', $binary);
        $response = $this->httpGetAot($docroot, $binary, '/docroot.php');
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString($resolved, $response);
        @unlink($binary);
        @rmdir($binaryDir);
    }

    public function testServeAotPopulatesServerProtocol(): void
    {
        $docroot = $this->makeDocroot([
            'proto.php' => <<<'PHP'
<?php
declare(strict_types=1);
echo 'P:', $_SERVER['SERVER_PROTOCOL'];
PHP,
        ]);
        $binaryDir = sys_get_temp_dir().'/phpc_serve_aot_sp_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($binaryDir));
        $binary = $binaryDir.'/app';
        $this->compileExample($docroot.'/proto.php', $binary);
        $response = $this->httpGetAot($docroot, $binary, '/proto.php');
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('P:HTTP/1.1', $response);
        @unlink($binary);
        @rmdir($binaryDir);
    }

    public function testServeAotPopulatesServerProtocolHttp10(): void
    {
        $docroot = $this->makeDocroot([
            'proto.php' => <<<'PHP'
<?php
declare(strict_types=1);
echo 'P:', $_SERVER['SERVER_PROTOCOL'];
PHP,
        ]);
        $binaryDir = sys_get_temp_dir().'/phpc_serve_aot_sp10_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($binaryDir));
        $binary = $binaryDir.'/app';
        $this->compileExample($docroot.'/proto.php', $binary);
        $response = $this->httpGetAot($docroot, $binary, '/proto.php', 'HTTP/1.0');
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('P:HTTP/1.0', $response);
        @unlink($binary);
        @rmdir($binaryDir);
    }

    public function testServeAotPopulatesContentLengthOnPost(): void
    {
        $body = 'abcdefghijkl';
        $docroot = $this->makeDocroot([
            'length.php' => <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_SERVER['CONTENT_LENGTH'];
PHP,
        ]);
        $binaryDir = sys_get_temp_dir().'/phpc_serve_aot_cl_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($binaryDir));
        $binary = $binaryDir.'/app';
        $this->compileExample($docroot.'/length.php', $binary);
        $response = $this->httpPostAot($docroot, $binary, '/length.php', $body);
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('12', $response);
        @unlink($binary);
        @rmdir($binaryDir);
    }

    public function testServeAot001SimpleWeb(): void
    {
        $docroot = $this->repoRoot.'/examples/001-SimpleWeb';
        $binaryDir = sys_get_temp_dir().'/phpc_serve_aot_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($binaryDir));
        $binary = $binaryDir.'/app';

        $this->compileExample($docroot.'/example.php', $binary);
        $response = $this->httpGetAot($docroot, $binary, '/example.php?name=Dev');
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('Hello', $response);
        $this->assertStringContainsString('Dev', $response);

        @unlink($binary);
        @rmdir($binaryDir);
    }

    private function compileExample(string $source, string $outfile): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $compile = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                $this->phpCmd,
                [$this->repoRoot.'/bin/compile.php', '-o', $outfile, $source]
            ),
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        $this->assertSame(
            0,
            $exitCode,
            'compile.php failed: '.trim($err !== false ? $err : '')
        );
        $this->assertFileExists($outfile, trim($err !== false ? $err : ''));
    }

    private function httpGetAot(
        string $docroot,
        string $binary,
        string $path,
        string $requestProtocol = 'HTTP/1.1'
    ): string
    {
        $port = $this->findFreePort();
        $addr = "127.0.0.1:{$port}";
        $env = array_merge($this->baseEnv(), $this->llvmEnv());
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = array_merge(
            $this->phpCmd,
            [$this->repoRoot.'/bin/serve-aot.php', $addr, $docroot, '--binary', $binary]
        );
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 15.0;
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
        $this->assertTrue($ready, 'serve-aot did not become ready');

        $conn = fsockopen('127.0.0.1', $port);
        $this->assertIsResource($conn);
        fwrite($conn, "GET {$path} {$requestProtocol}\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($conn);
        fclose($conn);

        proc_terminate($proc);
        proc_close($proc);

        return $response !== false ? $response : '';
    }

    private function httpPostAot(string $docroot, string $binary, string $path, string $body): string
    {
        $port = $this->findFreePort();
        $addr = "127.0.0.1:{$port}";
        $env = array_merge($this->baseEnv(), $this->llvmEnv());
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = array_merge(
            $this->phpCmd,
            [$this->repoRoot.'/bin/serve-aot.php', $addr, $docroot, '--binary', $binary]
        );
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 15.0;
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
        $this->assertTrue($ready, 'serve-aot did not become ready');

        $conn = fsockopen('127.0.0.1', $port);
        $this->assertIsResource($conn);
        $len = strlen($body);
        fwrite(
            $conn,
            "POST {$path} HTTP/1.1\r\n"
            ."Host: 127.0.0.1\r\n"
            ."Content-Type: application/x-www-form-urlencoded\r\n"
            ."Content-Length: {$len}\r\n"
            ."Connection: close\r\n\r\n"
            .$body
        );
        $response = stream_get_contents($conn);
        fclose($conn);

        proc_terminate($proc);
        proc_close($proc);

        return $response !== false ? $response : '';
    }

    /**
     * @return array<string, string>
     */
    private function llvmEnv(): array
    {
        $env = [];
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        return $env;
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    private function makeDocroot(array $files): string
    {
        $dir = sys_get_temp_dir().'/phpc_serve_aot_'.bin2hex(random_bytes(4));
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
            if (!str_contains($cmd[0], '/')) {
                $cmd[0] = PHP_BINARY;
            }
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

    private static function isLlvmReady(): bool
    {
        if (null !== self::$llvmReady) {
            return self::$llvmReady;
        }
        self::$llvmReady = LlvmToolchain::isReady(dirname(__DIR__, 2));

        return self::$llvmReady;
    }

    /**
     * @return list<string>
     */
    private static function llvmEnvPrefix(): array
    {
        return LlvmToolchain::envPrefix(dirname(__DIR__, 2));
    }
}
