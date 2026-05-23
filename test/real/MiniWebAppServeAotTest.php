<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * HTTP integration for phpc serve --aot on examples/003-MiniWebApp (issues #478, #610).
 *
 * @group llvm
 * @group serve
 * @group aot-link
 * @group miniwebapp
 * @group miniwebapp-aot-serve
 */
final class MiniWebAppServeAotTest extends TestCase
{
    private string $repoRoot;

    private string $projectDir;

    private string $docroot;

    private string $binary;

    /** @var list<string> */
    private array $phpCmd = [];

    protected function setUp(): void
    {
        if (!$this->serveAotGateEnabled()) {
            $this->markTestSkipped(
                'MINIWEBAPP_SERVE_AOT_GATE=0 and MINIWEBAPP_AOT_EXECUTE_GATE=0 — '
                .'set MINIWEBAPP_SERVE_AOT_GATE=1 or MINIWEBAPP_AOT_EXECUTE_GATE=1 (default) to run'
            );
        }
        if (false !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS') && '' !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS')) {
            $this->markTestSkipped('PHP_COMPILER_SKIP_SERVE_TESTS is set');
        }
        $this->repoRoot = dirname(__DIR__, 2);
        $this->projectDir = $this->repoRoot.'/'.MiniWebAppCgiEnv::PROJECT_REL;
        $this->docroot = $this->projectDir;
        if (!is_file($this->projectDir.'/public/index.php')) {
            $this->markTestSkipped('examples/003-MiniWebApp missing (#246)');
        }
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $phpc = $this->repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }

        $this->phpCmd = self::phpCommand();
        $this->buildProjectBinary($phpc);
        $binaryReal = realpath($this->projectDir.'/.phpc/bin/app');
        $this->assertNotFalse($binaryReal);
        $this->binary = $binaryReal;
    }

    public function testServeAot003QueryRouteHome(): void
    {
        $response = $this->httpGetAot(MiniWebAppCgiEnv::httpPathQueryRouteHome());
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $body = $this->responseBody($response);
        $this->assertStringContainsString(MiniWebAppCgiEnv::APP_NAME, $body);
        $this->assertStringContainsString('Home — '.MiniWebAppCgiEnv::APP_NAME, $body);
    }

    public function testServeAot003QueryRouteHello(): void
    {
        $response = $this->httpGetAot(MiniWebAppCgiEnv::httpPathQueryRouteHello());
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('Hello Dev', $this->responseBody($response));
    }

    /** PATH_INFO front-controller dispatch via serve-aot (#610, #747 execute parity). */
    public function testServeAot003PathInfoHello(): void
    {
        $response = $this->httpGetAot(MiniWebAppCgiEnv::httpPathPathInfoHello());
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('Hello Dev', $this->responseBody($response));
    }

    public function testServeAot003PostQueryRouteContact(): void
    {
        $scenario = MiniWebAppCgiEnv::postQueryRouteContact();
        $response = $this->httpPostAot(
            MiniWebAppCgiEnv::httpPathPostQueryRouteContact(),
            $scenario['REQUEST_BODY']
        );
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('Thank you, PostDev', $this->responseBody($response));
    }

    public function testServeAot003QueryRouteApiStatus(): void
    {
        $response = $this->httpGetAot(MiniWebAppCgiEnv::httpPathQueryRouteApiStatus());
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $body = $this->responseBody($response);
        $this->assertStringContainsString('"ok":true', $body);
        $this->assertStringContainsString('003-MiniWebApp', $body);
    }

    /**
     * Static assets from manifest "assets" beside public docroot (#610).
     */
    public function testServeAot003StaticCss(): void
    {
        $cssPath = $this->projectDir.'/assets/style.css';
        $this->assertFileExists($cssPath);
        $expected = (string) file_get_contents($cssPath);
        $this->assertStringContainsString('font-family: system-ui', $expected);

        $response = $this->httpGetAot(MiniWebAppCgiEnv::httpPathStaticCss());
        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('Content-Type: text/css', $response);
        $this->assertStringContainsString('font-family: system-ui', $this->responseBody($response));
    }

    private function buildProjectBinary(string $phpc): void
    {
        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $this->projectDir],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderr = false !== $stderr ? $stderr : '';
        if (0 !== $exit && PhpcBuild::isUserClassAotBlocked($stderr)) {
            $this->markTestSkipped(
                '003-MiniWebApp native AOT serve blocked: '.trim($stderr)
            );
        }
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));
        $this->assertFileExists($this->projectDir.'/.phpc/bin/app');
    }

    /**
     * @param list<string> $extraRequestHeaders
     */
    private function httpGetAot(
        string $path,
        string $requestProtocol = 'HTTP/1.1',
        array $extraRequestHeaders = []
    ): string {
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
            [
                $this->repoRoot.'/bin/serve-aot.php',
                $addr,
                $this->docroot,
                '--binary',
                $this->binary,
            ]
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
        $requestHeaders = array_merge(['Host: 127.0.0.1', 'Connection: close'], $extraRequestHeaders);
        $headerBlock = implode("\r\n", $requestHeaders);
        fwrite($conn, "GET {$path} {$requestProtocol}\r\n{$headerBlock}\r\n\r\n");
        $response = stream_get_contents($conn);
        fclose($conn);

        proc_terminate($proc);
        proc_close($proc);

        return $response !== false ? $response : '';
    }

    private function httpPostAot(string $path, string $body): string
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
            [
                $this->repoRoot.'/bin/serve-aot.php',
                $addr,
                $this->docroot,
                '--binary',
                $this->binary,
            ]
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

    private function responseBody(string $response): string
    {
        $pos = strpos($response, "\r\n\r\n");

        return false === $pos ? $response : substr($response, $pos + 4);
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
     * @return array<string, string>
     */
    private function llvmEnv(): array
    {
        $env = [];
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

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

    private static function serveAotGateEnabled(): bool
    {
        if ('1' === getenv('MINIWEBAPP_SERVE_AOT_GATE')) {
            return true;
        }
        $executeGate = getenv('MINIWEBAPP_AOT_EXECUTE_GATE');
        if (false === $executeGate || '' === $executeGate) {
            return true;
        }

        return '1' === $executeGate;
    }
}
