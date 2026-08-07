--TEST--
stdlib stream_socket_enable_crypto Reflection names/types (#27684, streamsfuncs.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('stream_socket_enable_crypto');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : '?',
        $p->isOptional() ? ' opt' : '', PHP_EOL;
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
$ss = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
try {
    $v = stream_socket_enable_crypto(
        stream: $ss[0],
        enable: false,
        crypto_method: STREAM_CRYPTO_METHOD_TLS_CLIENT
    );
    echo 'named=', var_export($v, true), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
?>
--EXPECT--
stream:?
enable:bool
crypto_method:?int opt
session_stream:? opt
ret=int|bool
named=true
