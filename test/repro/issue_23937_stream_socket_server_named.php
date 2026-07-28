<?php
/**
 * #23937 — stream_socket_server Zend stub named params (streamsfuncs.stub.php)
 */
$rf = new ReflectionFunction('stream_socket_server');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params:', implode(',', $names), "\n";

$errno = null;
$errstr = null;
try {
    $s = stream_socket_server(address: 'tcp://127.0.0.1:0', error_code: $errno, error_message: $errstr);
    echo ($s !== false) ? "named_ok\n" : "named_false\n";
    if ($s !== false) {
        fclose($s);
    }
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

$legacy = null;
try {
    stream_socket_server(localaddress: 'tcp://127.0.0.1:0');
    $legacy = 'localaddress accepted';
} catch (Throwable $e) {
    $legacy = $e->getMessage();
}
echo $legacy, "\n";
