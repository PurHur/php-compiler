<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\FastCgi\Record;
use PHPCompiler\Web\FastCgi\Request;
use PHPCompiler\Web\FastCgi\RequestHandler;

/**
 * FastCGI VM adapter over TCP (issue #173 slice 2).
 *
 * @group fastcgi
 */
final class FastCgiTest extends TestCase
{
    private string $repoRoot;

    private static ?string $aotBinary = null;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!is_file($this->repoRoot.'/examples/009-FastCGIWeb/example.php')) {
            $this->markTestSkipped('examples/009-FastCGIWeb missing (#2331)');
        }
    }

    public function testVmFastCgiHealthReturnsOk(): void
    {
        $stdoutBody = $this->runVmFastCgiTcpRequest($this->healthParams());
        $this->assertStringContainsString('ok', $stdoutBody);
    }

    public function testVmFastCgiPathInfoDiagnostics(): void
    {
        $params = $this->healthParams();
        $params['PATH_INFO'] = '/ping';
        $params['REQUEST_URI'] = '/example.php/ping';
        $stdoutBody = $this->runVmFastCgiTcpRequest($params);
        $this->assertStringContainsString('PATH_INFO=', $stdoutBody);
        $this->assertStringContainsString('REQUEST_URI=', $stdoutBody);
        $this->assertStringContainsString('SCRIPT_NAME=', $stdoutBody);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testKeepConnMultiplexesTwoRequestsOnOneStream(): void
    {
        $public = $this->repoRoot.'/examples/009-FastCGIWeb/public';
        if (!is_dir($public)) {
            $public = $this->repoRoot.'/examples/009-FastCGIWeb';
        }
        $script = is_file($public.'/example.php') ? $public.'/example.php' : $this->repoRoot.'/examples/009-FastCGIWeb/example.php';
        if (!is_file($script)) {
            $this->markTestSkipped('examples/009-FastCGIWeb missing (#2331)');
        }
        $docRoot = dirname($script);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        [$client, $server] = $pair;

        $params = [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_FILENAME' => $script,
            'SCRIPT_NAME' => '/'.basename($script),
            'REQUEST_URI' => '/'.basename($script),
            'DOCUMENT_ROOT' => $docRoot,
            'QUERY_STRING' => '',
            'CONTENT_LENGTH' => '0',
        ];
        fwrite(
            $client,
            Request::encode(1, $params, '', Record::ROLE_RESPONDER, Record::KEEP_CONN)
            .Request::encode(2, $params, '', Record::ROLE_RESPONDER, 0)
        );

        $handler = new RequestHandler($this->repoRoot.'/examples/009-FastCGIWeb');
        $handler->handleStream($server);
        fclose($server);

        $responses = $this->readAllFastCgiResponses($client);
        fclose($client);
        $this->assertCount(2, $responses);
        $this->assertStringContainsString('ok', $responses[0]['stdout']);
        $this->assertStringContainsString('ok', $responses[1]['stdout']);
        $this->assertTrue($responses[0]['end']);
        $this->assertTrue($responses[1]['end']);
    }

    /**
     * AOT binary behind FastCGI adapter (#173 slice 4, #2352 gate).
     *
     * @group llvm
     * @group aot
     * @group aot-link
     * @group fastcgiweb
     * @group fastcgiweb-aot-execute
     */
    public function testAotFastCgiHealthReturnsOk(): void
    {
        $binary = $this->ensureAotBinary();
        $stdoutBody = $this->runFastCgiTcpRequest($binary, $this->healthParams());
        $this->assertStringContainsString('ok', $stdoutBody);
    }

    /**
     * @group llvm
     * @group aot
     * @group aot-link
     * @group fastcgiweb
     * @group fastcgiweb-aot-execute
     */
    public function testAotFastCgiPathInfoDiagnostics(): void
    {
        $binary = $this->ensureAotBinary();
        $params = $this->healthParams();
        $params['PATH_INFO'] = '/ping';
        $params['REQUEST_URI'] = '/example.php/ping';
        $stdoutBody = $this->runFastCgiTcpRequest($binary, $params);
        $this->assertStringContainsString('PATH_INFO=', $stdoutBody);
        $this->assertStringContainsString('REQUEST_URI=', $stdoutBody);
        $this->assertStringContainsString('SCRIPT_NAME=', $stdoutBody);
    }

    private function ensureAotBinary(): string
    {
        $gate = getenv('FASTCGI_WEB_AOT_SMOKE_GATE');
        if (false === $gate || '' === $gate || '1' !== $gate) {
            $this->markTestSkipped(
                'FASTCGI_WEB_AOT_SMOKE_GATE=0 — set to 1 to run AOT FastCGI tests (#2352)'
            );
        }
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (null !== self::$aotBinary && is_file(self::$aotBinary)) {
            return self::$aotBinary;
        }

        $phpc = $this->repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }
        $project = $this->repoRoot.'/examples/009-FastCGIWeb';
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $proc = proc_open(
            [$phpc, 'build', '--project', $project],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
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
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));

        self::$aotBinary = $project.'/.phpc/bin/app';
        $this->assertFileExists(self::$aotBinary);

        return self::$aotBinary;
    }

    /**
     * @return array<string, string>
     */
    private function healthParams(): array
    {
        $public = $this->repoRoot.'/examples/009-FastCGIWeb/public';
        if (!is_dir($public)) {
            $public = $this->repoRoot.'/examples/009-FastCGIWeb';
        }
        $script = is_file($public.'/example.php') ? $public.'/example.php' : $this->repoRoot.'/examples/009-FastCGIWeb/example.php';
        $docRoot = dirname($script);

        return [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_FILENAME' => $script,
            'SCRIPT_NAME' => '/'.basename($script),
            'REQUEST_URI' => '/'.basename($script),
            'DOCUMENT_ROOT' => $docRoot,
            'QUERY_STRING' => '',
            'CONTENT_LENGTH' => '0',
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function runVmFastCgiTcpRequest(array $params): string
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $bound = stream_socket_get_name($server, false);
        $this->assertIsString($bound);
        fclose($server);

        $listener = proc_open(
            [
                PHP_BINARY,
                $this->repoRoot.'/bin/fcgi.php',
                '--listen',
                $bound,
                $this->repoRoot.'/examples/009-FastCGIWeb',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repoRoot
        );
        $this->assertIsResource($listener);
        fclose($pipes[0]);
        fclose($pipes[1]);
        stream_set_blocking($pipes[2], false);

        usleep(200000);

        $client = @stream_socket_client('tcp://'.$bound, $errno, $errstr, 5);
        $this->assertNotFalse($client, $errstr);

        fwrite($client, Request::encode(1, $params, ''));

        $stdoutBody = '';
        $endSeen = false;
        while (!$endSeen) {
            $record = Record::readFromStream($client);
            if (null === $record) {
                break;
            }
            if (Record::STDOUT === $record['type']) {
                $stdoutBody .= $record['content'];
            }
            if (Record::END_REQUEST === $record['type']) {
                $endSeen = true;
            }
        }
        fclose($client);
        proc_terminate($listener);
        proc_close($listener);

        $this->assertTrue($endSeen);

        return $stdoutBody;
    }

    /**
     * @param array<string, string> $params
     */
    private function runFastCgiTcpRequest(string $binary, array $params): string
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $bound = stream_socket_get_name($server, false);
        $this->assertIsString($bound);
        fclose($server);

        $listener = proc_open(
            [
                PHP_BINARY,
                $this->repoRoot.'/bin/fcgi.php',
                '--listen',
                $bound,
                '--binary',
                $binary,
                $this->repoRoot.'/examples/009-FastCGIWeb',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repoRoot
        );
        $this->assertIsResource($listener);
        fclose($pipes[0]);
        fclose($pipes[1]);
        stream_set_blocking($pipes[2], false);

        usleep(200000);

        $client = @stream_socket_client('tcp://'.$bound, $errno, $errstr, 5);
        $this->assertNotFalse($client, $errstr);

        fwrite($client, Request::encode(1, $params, ''));

        $stdoutBody = '';
        $endSeen = false;
        while (!$endSeen) {
            $record = Record::readFromStream($client);
            if (null === $record) {
                break;
            }
            if (Record::STDOUT === $record['type']) {
                $stdoutBody .= $record['content'];
            }
            if (Record::END_REQUEST === $record['type']) {
                $endSeen = true;
            }
        }
        fclose($client);
        proc_terminate($listener);
        proc_close($listener);

        $this->assertTrue($endSeen);

        return $stdoutBody;
    }

    /**
     * @return list<array{stdout: string, end: bool}>
     */
    private function readAllFastCgiResponses($stream): array
    {
        $responses = [];
        $stdoutBody = '';
        $endSeen = false;
        while (true) {
            $record = Record::readFromStream($stream);
            if (null === $record) {
                if ('' !== $stdoutBody || $endSeen) {
                    $responses[] = ['stdout' => $stdoutBody, 'end' => $endSeen];
                }
                break;
            }
            if (Record::STDOUT === $record['type']) {
                $stdoutBody .= $record['content'];
            }
            if (Record::END_REQUEST === $record['type']) {
                $endSeen = true;
                $responses[] = ['stdout' => $stdoutBody, 'end' => true];
                $stdoutBody = '';
                $endSeen = false;
            }
        }

        return $responses;
    }
}
