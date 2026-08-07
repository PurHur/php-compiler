--TEST--
HTTP stream context notification callbacks fire (#28790)
--SKIPIF--
<?php
if (!function_exists('pcntl_fork') || !function_exists('stream_socket_server')) {
    echo 'skip pcntl_fork/stream_socket_server required';
}
?>
--FILE--
<?php
$n = 0;
$codes = [];
$ctx = stream_context_create(null, ['notification' => function ($c) use (&$n, &$codes) {
    $n++;
    $codes[] = $c;
}]);
$s = stream_socket_server('tcp://127.0.0.1:0');
$name = stream_socket_get_name($s, false);
$p = (int) substr($name, strrpos($name, ':') + 1);
if (pcntl_fork() === 0) {
    $c = stream_socket_accept($s, 2);
    if (false === $c) {
        exit(1);
    }
    $r = '';
    while (!str_contains($r, "\r\n\r\n")) {
        $chunk = fread($c, 1024);
        if (false === $chunk || '' === $chunk) {
            break;
        }
        $r .= $chunk;
    }
    fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length:2\r\n\r\nhi");
    exit(0);
}
usleep(200000);
$b = @file_get_contents("http://127.0.0.1:$p/", false, $ctx);
pcntl_wait($x);
echo 'body=', var_export($b, true), ' n=', $n, ' codes=', implode(',', $codes), "\n";
?>
--EXPECT--
body='hi' n=5 codes=2,4,5,7,7
