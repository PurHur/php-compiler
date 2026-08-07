<?php
/**
 * #27684 — stream_socket_enable_crypto Reflection names/types + named crypto_method.
 */
$r = new ReflectionFunction('stream_socket_enable_crypto');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : '?',
        $p->isOptional() ? ' opt' : '', "\n";
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$ss = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
try {
    var_export(stream_socket_enable_crypto(
        stream: $ss[0],
        enable: false,
        crypto_method: STREAM_CRYPTO_METHOD_TLS_CLIENT
    ));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
