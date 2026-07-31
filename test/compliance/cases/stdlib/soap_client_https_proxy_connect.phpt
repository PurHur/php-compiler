--TEST--
Stdlib: SoapClient HTTPS via proxy sends CONNECT (#26166, ext/soap/php_http.c)
--FILE--
<?php
declare(strict_types=1);

$wsdl = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
if (!is_file($wsdl)) {
    $wsdl = __DIR__ . '/test/fixtures/soap/echo.wsdl';
}

// Trace headers for https+proxy: path-only POST, no Proxy-Authorization on POST (#26166).
$dead = new SoapClient($wsdl, [
    'location' => 'https://soap.example.test:443/echo',
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'proxy_host' => '127.0.0.1',
    'proxy_port' => 1,
    'proxy_login' => 'proxuser',
    'proxy_password' => 'proxpass',
    'connection_timeout' => 1,
    'stream_context' => stream_context_create([
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]),
]);
try {
    $dead->__soapCall('echo', [['input' => 'hi']]);
    echo "unexpected_ok\n";
} catch (SoapFault $e) {
    echo "fault=1\n";
}
$h = $dead->__getLastRequestHeaders();
echo (is_string($h) && str_starts_with($h, "POST /echo HTTP/1.1\r\n")) ? "path_post=1\n" : "path_post=0\n";
echo (is_string($h) && !str_contains($h, 'Proxy-Authorization:')) ? "no_post_proxy_auth=1\n" : "no_post_proxy_auth=0\n";
echo (is_string($h) && !str_contains($h, 'https://soap.example.test')) ? "no_abs_uri=1\n" : "no_abs_uri=0\n";

// Mock CONNECT proxy: record CONNECT line, reply 200, then TLS fails → SoapFault.
$dir = sys_get_temp_dir() . '/phpc_soap_connect_' . getmypid();
@mkdir($dir);
$log = $dir . '/connect.log';
$proxyScript = $dir . '/proxy.php';
file_put_contents($proxyScript, <<<'PHP'
<?php
$log = $argv[1];
$portFile = $argv[2];
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) { fwrite(STDERR, "bind failed\n"); exit(1); }
$name = stream_socket_get_name($server, false);
if (!is_string($name) || !preg_match('#:(\d+)$#', $name, $m)) { fwrite(STDERR, "no port\n"); exit(1); }
file_put_contents($portFile, $m[1]);
$client = @stream_socket_accept($server, 5);
if (!$client) { exit(0); }
$buf = '';
while (!str_contains($buf, "\r\n\r\n")) {
    $chunk = fread($client, 1024);
    if ($chunk === false || $chunk === '') break;
    $buf .= $chunk;
}
file_put_contents($log, $buf);
fwrite($client, "HTTP/1.1 200 Connection Established\r\n\r\n");
fflush($client);
// Keep socket briefly so client can attempt TLS.
usleep(200000);
fclose($client);
fclose($server);
PHP);

$portFile = $dir . '/port';
@unlink($log);
@unlink($portFile);
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($proxyScript) . ' '
    . escapeshellarg($log) . ' ' . escapeshellarg($portFile);
$proc = proc_open($cmd, $desc, $pipes);
if (!\is_resource($proc) && !\is_object($proc)) {
    echo "proxy_spawn=0\n";
    exit(0);
}
if (isset($pipes[0]) && \is_resource($pipes[0])) {
    fclose($pipes[0]);
}
$port = null;
for ($i = 0; $i < 50; $i++) {
    if (is_file($portFile) && '' !== trim((string) file_get_contents($portFile))) {
        $port = (int) trim((string) file_get_contents($portFile));
        break;
    }
    usleep(50000);
}
if (null === $port || $port <= 0) {
    echo "proxy_port=0\n";
    proc_terminate($proc);
    proc_close($proc);
    exit(0);
}

$c = new SoapClient($wsdl, [
    'location' => 'https://soap.example.test:443/echo',
    'uri' => 'http://example.com/echo',
    'trace' => 1,
    'proxy_host' => '127.0.0.1',
    'proxy_port' => $port,
    'proxy_login' => 'proxuser',
    'proxy_password' => 'proxpass',
    'connection_timeout' => 2,
    'stream_context' => stream_context_create([
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]),
]);
try {
    $c->__soapCall('echo', [['input' => 'hi']]);
} catch (SoapFault $e) {
    // expected — mock has no TLS
}
$raw = is_file($log) ? (string) file_get_contents($log) : '';
echo (str_starts_with($raw, 'CONNECT soap.example.test:443 HTTP/1.1')) ? "connect=1\n" : "connect=0\n";
echo (str_contains($raw, 'Proxy-Authorization: Basic ' . base64_encode('proxuser:proxpass'))) ? "connect_auth=1\n" : "connect_auth=0\n";
echo (str_contains($raw, 'Host: soap.example.test:443')) ? "connect_host=1\n" : "connect_host=0\n";

proc_terminate($proc);
proc_close($proc);
@unlink($log);
@unlink($portFile);
@unlink($proxyScript);
@rmdir($dir);
?>
--EXPECT--
fault=1
path_post=1
no_post_proxy_auth=1
no_abs_uri=1
connect=1
connect_auth=1
connect_host=1
