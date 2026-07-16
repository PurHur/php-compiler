<?php
declare(strict_types=1);
// Prefer Pure/FFI mkstemp cast for php://temp (#19691); host tmpfile is fallback only.
$m = fopen("php://temp", "r+");
fwrite($m, "x");
rewind($m);
$r = [$m]; $w = null; $e = null;
$n = stream_select($r, $w, $e, 0);
var_export($n);
echo "\n";
$mem = fopen("php://memory", "r+");
fwrite($mem, "x");
rewind($mem);
$r = [$mem]; $w = null; $e = null;
try {
    stream_select($r, $w, $e, 0);
    echo "memory_unexpected_ok\n";
} catch (Throwable $ex) {
    echo "memory:", get_class($ex), "\n";
}
