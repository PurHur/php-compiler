<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * SoapClient Set-Cookie ingest + Cookie path/domain/secure filters (#31843 / #31844).
 *
 * @covers issue #31843
 * @covers issue #31844
 */
final class SoapSetCookieIngestTest extends TestCase
{
    /** @var false|string */
    private $savedProfile;

    /** @var resource|null */
    private $proc = null;

    protected function setUp(): void
    {
        $this->savedProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapClient');
        }
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

    public function testSetCookieIngestAndSecureFilter(): void
    {
        if (!\function_exists('proc_open') || !\function_exists('stream_socket_client')) {
            $this->markTestSkipped('proc_open/stream_socket_client required');
        }

        $root = dirname(__DIR__, 2);
        $serverScript = $root.'/test/fixtures/soap/mock_http_setcookie_server.php';
        $wsdl = $root.'/test/fixtures/soap/echo.wsdl';
        $body = $root.'/test/fixtures/soap/echo.response.xml';
        $port = 28300 + (getmypid() % 1000);
        $readyFile = sys_get_temp_dir().'/soap31843-unit-'.getmypid().'.txt';
        @unlink($readyFile);

        $phpBin = \defined('PHP_BINARY') && '' !== (string) PHP_BINARY ? (string) PHP_BINARY : 'php';
        $this->proc = proc_open(
            [$phpBin, $serverScript, (string) $port, $readyFile, $body, '6'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        self::assertIsResource($this->proc);
        fclose($pipes[0]);
        if (isset($pipes[1]) && \is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && \is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        $deadline = microtime(true) + 5.0;
        while (!is_file($readyFile) && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertFileExists($readyFile, 'mock SOAP Set-Cookie server not ready');

        $location = 'http://127.0.0.1:'.$port.'/echo';
        $code = sprintf(
            <<<'PHP'
<?php
$location = %s;
$wsdl = %s;
$c = new SoapClient($wsdl, [
    'location' => $location,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'keep_alive' => false,
]);
$c->__soapCall('echo', [['input' => 'hello']]);
$cookies = $c->__getCookies();
$sess = $cookies['sess'] ?? null;
echo 'sess=', (int) (is_array($sess) && ($sess[0] ?? null) === 'abc123'
    && ($sess[1] ?? null) === '/echo'
    && ($sess[2] ?? null) === '127.0.0.1'), "\n";
$tok = $cookies['tok'] ?? null;
echo 'tok=', (int) (is_array($tok) && ($tok[0] ?? null) === 'xyz' && !empty($tok[3])), "\n";
$c->__soapCall('echo', [['input' => 'hello2']]);
$req = (string) $c->__getLastRequestHeaders();
echo 'send_sess=', (int) str_contains($req, 'sess=abc123'), "\n";
echo 'block_tok=', (int) (!str_contains($req, 'tok=xyz')), "\n";
PHP,
            var_export($location, true),
            var_export($wsdl, true)
        );

        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'soap_setcookie_ingest_unit.php'));
        $out = (string) ob_get_clean();
        @unlink($readyFile);

        $this->assertSame("sess=1\ntok=1\nsend_sess=1\nblock_tok=1\n", $out);
    }
}
