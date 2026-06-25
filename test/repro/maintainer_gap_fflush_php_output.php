<?php

declare(strict_types=1);

// Issue #11432 — fflush(php://output) must return true (ext/standard/streams.c).
$out = fopen('php://output', 'w');
$mem = fopen('php://memory', 'w+');
$outOk = is_resource($out) && fflush($out);
$memOk = is_resource($mem) && fflush($mem);
if (is_resource($out)) {
    fclose($out);
}
if (is_resource($mem)) {
    fclose($mem);
}
echo ($outOk && $memOk) ? "fflush_ok\n" : "fflush_fail\n";
