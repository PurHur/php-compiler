<?php
/** Issue #28568 — socket_* Reflection: Socket params, |false returns, ?int port/length. */
foreach (['socket_connect', 'socket_bind', 'socket_listen', 'socket_read', 'socket_write'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $ps[] = $p->getName() . ':' . ($p->hasType() ? (string) $p->getType() : '?');
    }
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'untyped', ' [', implode(', ', $ps), ']', PHP_EOL;
}
