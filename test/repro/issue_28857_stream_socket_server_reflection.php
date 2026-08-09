<?php
/** Issue #28857 — stream_socket_server Reflection: untyped error outs, no return (match Zend stubs). */
$r = new ReflectionFunction('stream_socket_server');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none', $p->isPassedByReference() ? '&' : '', $p->isOptional() ? '?' : '', PHP_EOL;
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
