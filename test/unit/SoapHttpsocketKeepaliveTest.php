<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * SoapClient::$httpsocket keep-alive attach via stream HTTP (#24913).
 *
 * @covers issue #24913
 */
final class SoapHttpsocketKeepaliveTest extends TestCase
{
    /** @var false|string */
    private $savedProfile;

    /** @var resource|null */
    private $proc = null;

    protected function setUp(): void
    {
        $this->savedProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
        if (false === $this->savedProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->savedProfile);
        }
    }

    public function testHttpsocketKeepAliveReuseAndClose(): void
    {
        if (!\function_exists('proc_open') || !\function_exists('stream_socket_client')) {
            $this->markTestSkipped('proc_open/stream_socket_client required');
        }

        $root = dirname(__DIR__, 2);
        $serverScript = $root.'/test/fixtures/soap/mock_http_echo_server.php';
        $wsdl = $root.'/test/fixtures/soap/echo.wsdl';
        $fixture = $root.'/test/fixtures/soap/echo.response.xml';
        $port = 25000 + (getmypid() % 1000);
        $readyFile = sys_get_temp_dir().'/soap24913-unit-'.getmypid().'.txt';
        @unlink($readyFile);

        $phpBin = \defined('PHP_BINARY') && '' !== (string) PHP_BINARY ? (string) PHP_BINARY : 'php';
        $this->proc = proc_open(
            [$phpBin, $serverScript, (string) $port, $readyFile, '6'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root
        );
        self::assertIsResource($this->proc);
        fclose($pipes[0]);

        $deadline = microtime(true) + 5.0;
        while (!is_file($readyFile) && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertFileExists($readyFile, 'mock SOAP HTTP server not ready');

        $location = 'http://127.0.0.1:'.$port.'/echo';
        $code = sprintf(
            <<<'PHP'
<?php
$location = %s;
$wsdl = %s;
$fixture = %s;
$ka = new SoapClient($wsdl, [
    'location' => $location,
    'uri' => 'http://example.com/echo',
    'keep_alive' => true,
]);
$ka->__soapCall('echo', [['input' => 'hello']]);
$a = $ka->httpsocket;
$ka->__soapCall('echo', [['input' => 'hello']]);
$b = $ka->httpsocket;
echo 'ka=', (int) (null !== $a), (int) (null !== $b), (int) ($a === $b), "\n";
$c = new SoapClient($wsdl, [
    'location' => $location,
    'uri' => 'http://example.com/echo',
    'keep_alive' => false,
]);
$c->__soapCall('echo', [['input' => 'hello']]);
echo 'close=', (int) (null === $c->httpsocket), "\n";
$f = new SoapClient(null, ['location' => $fixture, 'uri' => 'http://example.com/echo']);
$f->__soapCall('echo', ['x']);
echo 'fix=', (int) (null === $f->httpsocket), "\n";
PHP,
            var_export($location, true),
            var_export($wsdl, true),
            var_export($fixture, true)
        );

        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'soap_httpsocket_keepalive_unit.php'));
        $out = (string) ob_get_clean();
        @unlink($readyFile);

        $this->assertSame("ka=111\nclose=1\nfix=1\n", $out);
    }
}
