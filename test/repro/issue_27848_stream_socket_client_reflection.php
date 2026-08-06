<?php
/** Issue #27848 — stream_socket_client Reflection: untyped error outs, ?float timeout, no return. */
$r = new ReflectionFunction('stream_socket_client');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none', $p->isPassedByReference() ? '&' : '', $p->isOptional() ? '?' : '', PHP_EOL;
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
