<?php
/**
 * #23938 — stream_socket_accept / stream_socket_get_name Zend stub named params
 */
foreach (['stream_socket_accept', 'stream_socket_get_name'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}

// Named resolution must succeed (TypeError / warning on STDIN is fine).
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
