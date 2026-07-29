<?php
/**
 * Issue #24642 — socket_strerror Reflection error_code
 * (ext/sockets/sockets.stub.php).
 */
$r = new ReflectionFunction('socket_strerror');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
echo socket_strerror(error_code: 0), "\n";
try {
    socket_strerror(errno: 0);
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
