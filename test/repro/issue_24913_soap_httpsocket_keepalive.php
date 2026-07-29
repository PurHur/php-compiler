<?php
/**
 * #24913 — SoapClient::$httpsocket keep-alive attach after real HTTP POST.
 *
 * Spawns a local mock SOAP server, then drives the VM Runtime (PROFILE=8.4).
 * Run:
 *   ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && PHP_COMPILER_PROFILE=8.4 php test/repro/issue_24913_soap_httpsocket_keepalive.php'
 */
declare(strict_types=1);

use PHPCompiler\Runtime;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$prev = getenv('PHP_COMPILER_PROFILE');
putenv('PHP_COMPILER_PROFILE=8.4');

$serverScript = dirname(__DIR__).'/fixtures/soap/mock_http_echo_server.php';
$wsdl = dirname(__DIR__).'/fixtures/soap/echo.wsdl';
$fixture = dirname(__DIR__).'/fixtures/soap/echo.response.xml';
$port = 24000 + (getmypid() % 1000);
$readyFile = sys_get_temp_dir().'/soap24913-ready-'.getmypid().'.txt';
@unlink($readyFile);

$phpBin = \defined('PHP_BINARY') && '' !== (string) PHP_BINARY ? (string) PHP_BINARY : 'php';
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', '/dev/null', 'w'],
    2 => ['file', '/dev/null', 'w'],
];
$proc = proc_open(
    [$phpBin, $serverScript, (string) $port, $readyFile, '6'],
    $descriptors,
    $pipes,
    dirname(__DIR__, 2)
);
if (!\is_resource($proc)) {
    fwrite(STDERR, "failed to start mock server\n");
    exit(1);
}
fclose($pipes[0]);

$deadline = microtime(true) + 5.0;
while (!is_file($readyFile) && microtime(true) < $deadline) {
    usleep(20000);
}
if (!is_file($readyFile)) {
    proc_terminate($proc);
    fwrite(STDERR, "mock server not ready\n");
    exit(1);
}

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
    'trace' => true,
    'keep_alive' => true,
]);
$r1 = $ka->__soapCall('echo', [['input' => 'hello']]);
$sock1 = $ka->httpsocket;
$alive1 = (int) (null !== $sock1);
$r2 = $ka->__soapCall('echo', [['input' => 'hello']]);
$sock2 = $ka->httpsocket;
$alive2 = (int) (null !== $sock2);
$same = (int) ($alive1 && $alive2 && $sock1 === $sock2);
echo 'ka_out=', (is_string($r1) ? $r1 : gettype($r1)), ' alive1=', $alive1, ' alive2=', $alive2, ' same=', $same, "\n";

$close = new SoapClient($wsdl, [
    'location' => $location,
    'uri' => 'http://example.com/echo',
    'trace' => true,
    'keep_alive' => false,
]);
$r3 = $close->__soapCall('echo', [['input' => 'hello']]);
$alive3 = (int) (null !== $close->httpsocket);
echo 'close_out=', (is_string($r3) ? $r3 : gettype($r3)), ' after_close_null=', (int) (0 === $alive3), "\n";

$f = new SoapClient(null, ['location' => $fixture, 'uri' => 'http://example.com/echo']);
$f->__soapCall('echo', ['x']);
echo 'fixture_null=', (int) (null === $f->httpsocket), "\n";
PHP,
    var_export($location, true),
    var_export($wsdl, true),
    var_export($fixture, true)
);

try {
    $runtime = new Runtime();
    ob_start();
    $runtime->run($runtime->parseAndCompile($code, 'issue_24913_soap_httpsocket.php'));
    $out = (string) ob_get_clean();
    echo $out;
    $ok = str_contains($out, 'alive1=1')
        && str_contains($out, 'alive2=1')
        && str_contains($out, 'same=1')
        && str_contains($out, 'after_close_null=1')
        && str_contains($out, 'fixture_null=1');
    exit($ok ? 0 : 2);
} finally {
    proc_terminate($proc);
    proc_close($proc);
    @unlink($readyFile);
    if (false === $prev || null === $prev) {
        putenv('PHP_COMPILER_PROFILE');
    } else {
        putenv('PHP_COMPILER_PROFILE='.$prev);
    }
}
