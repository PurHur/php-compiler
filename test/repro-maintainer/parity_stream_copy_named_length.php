<?php

/** Issue #11079 — stream_copy_to_stream(from:/to:/length:) named parameters. */

$src = fopen('php://memory', 'r+');
$dst = fopen('php://memory', 'w+');
fwrite($src, 'hello');
rewind($src);
$n = stream_copy_to_stream(from: $src, to: $dst, length: 3);
fclose($src);
fclose($dst);
echo (3 === $n) ? "OK\n" : "FAIL n=$n\n";
