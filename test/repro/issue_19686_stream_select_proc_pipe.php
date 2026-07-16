<?php
declare(strict_types=1);

$des = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$p = proc_open('printf hi', $des, $pipes);
if (!\is_resource($p) && !(\is_object($p) && $p instanceof \Process)) {
    fwrite(STDERR, "proc_open failed\n");
    exit(1);
}
usleep(50000);
$r = [$pipes[1]];
$w = null;
$e = null;
$n = stream_select($r, $w, $e, 1);
var_export($n);
echo "\n";
if (\is_int($n) && $n > 0) {
    echo stream_get_contents($pipes[1]), "\n";
} else {
    echo "NOT_READY\n";
}
foreach ($pipes as $x) {
    fclose($x);
}
proc_close($p);
