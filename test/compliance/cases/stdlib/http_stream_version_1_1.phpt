--TEST--
HTTP stream wrapper request line is HTTP/1.1 (#28789)
--SKIPIF--
<?php
if (!function_exists('pcntl_fork') || !function_exists('stream_socket_server')) {
    echo 'skip pcntl_fork/stream_socket_server required';
}
?>
--FILE--
<?php
$s = stream_socket_server('tcp://127.0.0.1:0');
$name = stream_socket_get_name($s, false);
$p = (int) substr($name, strrpos($name, ':') + 1);
$reqFile = sys_get_temp_dir() . '/phpc_http_ver_' . getmypid() . '_' . mt_rand() . '.txt';
@unlink($reqFile);
if (pcntl_fork() === 0) {
    $c = stream_socket_accept($s, 2);
    if (false === $c) {
        file_put_contents($reqFile, 'ACCEPT_FAIL');
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
    file_put_contents($reqFile, '' === $r ? 'EMPTY' : $r);
    fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length:0\r\n\r\n");
    exit(0);
}
usleep(200000);
@file_get_contents("http://127.0.0.1:$p/");
pcntl_wait($x);
$raw = is_file($reqFile) ? (string) file_get_contents($reqFile) : '';
@unlink($reqFile);
preg_match('#HTTP/([0-9.]+)#', $raw, $m);
echo 'version=', $m[1] ?? '?', "\n";
?>
--EXPECT--
version=1.1
