<?php
/**
 * Issue #24373 — socket_bind/connect/read/write/set_option Reflection names
 * (ext/sockets/sockets.stub.php).
 */
foreach (['socket_bind', 'socket_connect', 'socket_read', 'socket_write', 'socket_set_option'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    socket_bind(socket: $s, address: '127.0.0.1', port: 0);
    echo "bind-ok\n";
} catch (Throwable $e) {
    echo 'bind:', $e->getMessage(), "\n";
}
try {
    socket_bind(socket: $s, addr: '127.0.0.1', port: 0);
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
socket_close($s);
