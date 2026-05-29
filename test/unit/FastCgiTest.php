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
 * @group serve
 */
final class FastCgiTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!is_file($this->repoRoot.'/examples/009-FastCGIWeb/example.php')) {
            $this->markTestSkipped('examples/009-FastCGIWeb missing (#2331)');
        }
    }

    public function testVmFastCgiHealthReturnsOk(): void
    {
        $public = $this->repoRoot.'/examples/009-FastCGIWeb/public';
        if (!is_dir($public)) {
            $public = $this->repoRoot.'/examples/009-FastCGIWeb';
        }
        $script = is_file($public.'/example.php') ? $public.'/example.php' : $this->repoRoot.'/examples/009-FastCGIWeb/example.php';
        $this->assertFileExists($script);
        $docRoot = dirname($script);

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

        $params = [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_FILENAME' => $script,
            'SCRIPT_NAME' => '/'.basename($script),
            'REQUEST_URI' => '/'.basename($script),
            'DOCUMENT_ROOT' => $docRoot,
            'QUERY_STRING' => '',
            'CONTENT_LENGTH' => '0',
        ];
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
        $this->assertStringContainsString('ok', $stdoutBody);
    }

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
