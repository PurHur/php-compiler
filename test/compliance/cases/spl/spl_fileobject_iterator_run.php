<?php
declare(strict_types=1);
$f = new SplTempFileObject();
$f->fwrite("line1\nline2\n");
$f->rewind();
$line = $f->fgets();
echo $line === "line1\n" ? "rewind-fgets-ok\n" : "rewind-fgets-bad\n";
$lines = [];
foreach ($f as $k => $v) {
    $lines[$k] = $v;
}
echo $lines === [0 => "line1\n", 1 => "line2\n"] ? "foreach-ok\n" : "foreach-bad\n";
