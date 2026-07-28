--TEST--
stdlib stream_socket_accept/get_name Zend stub named params (#23938)
--FILE--
<?php
foreach (['stream_socket_accept', 'stream_socket_get_name'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}
try {
    @stream_socket_accept(socket: STDIN);
    echo "accept_named_resolved\n";
} catch (Throwable $e) {
    echo (str_contains($e->getMessage(), 'Unknown named parameter'))
        ? $e->getMessage()."\n"
        : "accept_named_resolved\n";
}
try {
    @stream_socket_get_name(socket: STDIN, remote: false);
    echo "get_name_named_resolved\n";
} catch (Throwable $e) {
    echo (str_contains($e->getMessage(), 'Unknown named parameter'))
        ? $e->getMessage()."\n"
        : "get_name_named_resolved\n";
}
try {
    stream_socket_accept(serverstream: STDIN);
    echo "serverstream accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    stream_socket_get_name(stream: STDIN, want_peer: false);
    echo "want_peer accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
stream_socket_accept:socket,timeout,peer_name
stream_socket_get_name:socket,remote
accept_named_resolved
get_name_named_resolved
Unknown named parameter $serverstream
Unknown named parameter $stream
