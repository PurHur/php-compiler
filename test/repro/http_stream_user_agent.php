<?php
/**
 * #28792 — http context user_agent must become User-Agent request header.
 *
 * Local stream_socket_server + pcntl_fork — no network dependency.
 */
if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl_fork required\n");
    exit(1);
}
$s = stream_socket_server('tcp://127.0.0.1:0');
$name = stream_socket_get_name($s, false);
$p = (int) substr($name, strrpos($name, ':') + 1);
$reqFile = sys_get_temp_dir() . '/phpc_http_ua_' . getmypid() . '_' . mt_rand() . '.txt';
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
    fwrite($c, "HTTP/1.0 200 OK\r\nContent-Length:1\r\n\r\nx");
    exit(0);
}
usleep(200000);
$ctx = stream_context_create(['http' => ['user_agent' => 'ParityBot/1.0']]);
@file_get_contents("http://127.0.0.1:$p/", false, $ctx);
pcntl_wait($x);
$raw = is_file($reqFile) ? (string) file_get_contents($reqFile) : '';
@unlink($reqFile);
$has = str_contains($raw, "User-Agent: ParityBot/1.0") ? 'yes' : 'no';
echo 'has_ua=', $has, PHP_EOL;
