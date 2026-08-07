<?php
/**
 * #28791 — http follow_location must follow 302 Location (php-src http_fopen_wrapper.c).
 */
if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl_fork required\n");
    exit(1);
}
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
    fwrite($c, "HTTP/1.1 302 Found\r\nLocation: /target\r\nContent-Length:0\r\nConnection: close\r\n\r\n");
    fclose($c);
    $c2 = stream_socket_accept($s, 2);
    if (false === $c2) {
        exit(1);
    }
    $r = '';
    while (!str_contains($r, "\r\n\r\n")) {
        $chunk = fread($c2, 1024);
        if (false === $chunk || '' === $chunk) {
            break;
        }
        $r .= $chunk;
    }
    fwrite($c2, "HTTP/1.1 200 OK\r\nContent-Length:6\r\nConnection: close\r\n\r\nTARGET");
    exit(0);
}
usleep(200000);
$ctx = stream_context_create(['http' => ['follow_location' => 1, 'max_redirects' => 5]]);
$b = @file_get_contents("http://127.0.0.1:$p/redir", false, $ctx);
pcntl_wait($x);
echo 'follow=', var_export($b, true), PHP_EOL;
