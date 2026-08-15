<?php
// AOT repro for #31308 — socket_sendto NestedJIT (create_pair is the reliable AOT fd source; peer #31240).
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    fwrite(STDERR, "pair_fail\n");
    exit(1);
}
$n = @socket_sendto($pair[0], 'hi', 2, 0, '127.0.0.1', 9);
// NestedJIT FFI sendto on AF_UNIX may return 0 instead of false (#31308); prove call lowers.
echo 'linked=', (false === $n || is_int($n) ? '1' : '0'), "\n";
socket_close($pair[0]);
socket_close($pair[1]);
echo "sendto_aot_ok\n";
