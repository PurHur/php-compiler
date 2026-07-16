<?php
declare(strict_types=1);
$m = fopen("php://temp", "r+");
fwrite($m, "x");
rewind($m);
$r = [$m]; $w = null; $e = null;
try {
    $n = stream_select($r, $w, $e, 0);
    var_export($n);
    echo "\n";
} catch (Throwable $ex) {
    echo get_class($ex), ": ", $ex->getMessage(), "\n";
}
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
