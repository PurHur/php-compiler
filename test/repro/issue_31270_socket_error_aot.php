<?php
// AOT repro for #31270 — socket_strerror/last_error/clear_error NestedJIT.
echo socket_strerror(111), "\n";
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    fwrite(STDERR, "pair_fail\n");
    exit(1);
}
@socket_connect($pair[0], '/tmp/phpc-31270-repro-missing.sock');
echo 'err=', socket_last_error($pair[0]), "\n";
socket_clear_error($pair[0]);
echo 'cleared=', socket_last_error($pair[0]), "\n";
socket_close($pair[0]);
socket_close($pair[1]);
