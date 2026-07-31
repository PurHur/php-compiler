<?php
declare(strict_types=1);

/**
 * Repro for #26166 — HTTPS SoapClient via HTTP proxy must CONNECT.
 * Run inside docker-exec after php8.2-soap is installed (extension_loaded('soap')).
 */

$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
if (!is_file($wsdl)) {
    fwrite(STDERR, "missing fixture wsdl\n");
    exit(1);
}
if (!extension_loaded('soap') || !class_exists('SoapClient')) {
    fwrite(STDERR, "soap not advertised — install php-soap for host Zend\n");
    exit(2);
}

$dir = sys_get_temp_dir() . '/phpc_soap_connect_repro_' . getmypid();
@mkdir($dir);
$log = $dir . '/connect.log';
$portFile = $dir . '/port';
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
usleep(200000);
fclose($client);
fclose($server);
PHP);

$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($proxyScript).' '
    .escapeshellarg($log).' '.escapeshellarg($portFile);
$proc = proc_open($cmd, $desc, $pipes);
if ($proc === false) {
    fwrite(STDERR, "proc_open failed\n");
    exit(1);
}
fclose($pipes[0]);
$port = null;
for ($i = 0; $i < 50; $i++) {
    if (is_file($portFile) && '' !== trim((string) file_get_contents($portFile))) {
        $port = (int) trim((string) file_get_contents($portFile));
        break;
    }
    usleep(50000);
}
if ($port === null || $port <= 0) {
    fwrite(STDERR, "proxy port missing\n");
    fwrite(STDERR, stream_get_contents($pipes[2]));
    proc_terminate($proc);
    proc_close($proc);
    exit(1);
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
    echo 'fault=', $e->faultcode, "\n";
}
$h = $c->__getLastRequestHeaders();
echo (is_string($h) && str_starts_with($h, "POST /echo HTTP/1.1\r\n")) ? "path_post=1\n" : "path_post=0\n";
echo (is_string($h) && !str_contains($h, 'Proxy-Authorization:')) ? "no_post_proxy_auth=1\n" : "no_post_proxy_auth=0\n";
$raw = is_file($log) ? (string) file_get_contents($log) : '';
echo (str_starts_with($raw, 'CONNECT soap.example.test:443 HTTP/1.1')) ? "connect=1\n" : "connect=0\n";
echo (str_contains($raw, 'Proxy-Authorization: Basic ' . base64_encode('proxuser:proxpass'))) ? "connect_auth=1\n" : "connect_auth=0\n";
echo "RAW=\n", $raw, "\n";

proc_terminate($proc);
proc_close($proc);
