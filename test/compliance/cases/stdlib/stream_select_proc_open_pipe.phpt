--TEST--
stdlib stream_select() on proc_open stdout pipe resumes child (#19686)
--FILE--
<?php
declare(strict_types=1);
$des = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$p = proc_open('printf hi', $des, $pipes);
if (false === $p) {
    fwrite(STDERR, "proc_open failed\n");
    exit(1);
}
usleep(50000);
$read = [$pipes[1]];
$write = null;
$except = null;
$n = stream_select($read, $write, $except, 1);
if (!is_int($n) || $n < 1) {
    fwrite(STDERR, 'expected ready>=1, got ');
    var_export($n);
    fwrite(STDERR, "\n");
    exit(1);
}
echo stream_get_contents($pipes[1]), "\n";
foreach ($pipes as $x) {
    fclose($x);
}
proc_close($p);
?>
--EXPECT--
hi
