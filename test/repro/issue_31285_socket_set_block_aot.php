<?php

declare(strict_types=1);

// #31285 — thin AOT socket_set_block / socket_set_nonblock (re-#6289)
// Uses create_pair: socket_create() thin AOT returns false on this host (pre-existing).

$pair = [];
$ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
if (!$ok) {
    echo "pair fail\n";
    exit(1);
}
$nb = socket_set_nonblock($pair[0]);
$b = socket_set_block($pair[0]);
echo 'nonblock=', var_export($nb, true), "\n";
echo 'block=', var_export($b, true), "\n";
socket_close($pair[0]);
socket_close($pair[1]);
echo "ok\n";
