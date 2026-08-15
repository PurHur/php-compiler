<?php

declare(strict_types=1);

/**
 * Repro #31327 — thin AOT must lower socket_getsockname()/socket_getpeername().
 * AF_UNIX create_pair is the reliable AOT socket source (#27423 / #31308).
 * VM semantic path (create_listen + true fills) is covered separately in unit/AOT when available.
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_getsockname|getpeername)
 */
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    fwrite(STDERR, "pair_fail\n");
    exit(1);
}
$addr = '';
$port = 0;
$r = @socket_getsockname($pair[0], $addr, $port);
echo 'getsockname_linked=', (is_bool($r) ? '1' : '0'), "\n";
$paddr = '';
$pport = 0;
$r2 = @socket_getpeername($pair[0], $paddr, $pport);
echo 'getpeername_linked=', (is_bool($r2) ? '1' : '0'), "\n";
socket_close($pair[0]);
socket_close($pair[1]);

// VM-friendly AF_INET path when create_listen works (host Zend/VM; may be false under thin AOT).
$server = @socket_create_listen(0);
if (is_object($server)) {
    $a = '';
    $p = 0;
    $ok = @socket_getsockname($server, $a, $p);
    echo 'inet_getsockname=', var_export($ok, true), "\n";
    if ($ok) {
        echo 'inet_addr_ok=', (is_string($a) && '' !== $a ? '1' : '0'), "\n";
        echo 'inet_port_ok=', ($p > 0 ? '1' : '0'), "\n";
    }
    socket_close($server);
} else {
    echo "inet_create_listen_skip\n";
}
echo "getsockname_aot_ok\n";
